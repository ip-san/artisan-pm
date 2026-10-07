<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EnumerationType;
use App\Enums\ImportStatus;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\IssueService;
use App\Support\Format\Hours;
use App\Support\Import\CsvReader;
use App\Support\Mail\MailSuppression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Reads the CSV stored for an IssueImport, maps each row's columns per
 * the import's stored column_mapping, and creates one Issue per row via
 * IssueService — the same path a hand-created issue goes through. A row
 * that can't be mapped or created is recorded in `errors` and skipped;
 * the rest of the file still imports.
 */
final class ImportIssuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * A large CSV can take minutes. Kept under the database queue's
     * retry_after (660s) and the 15-minute overlap lock of the scheduler's
     * queue:work (routes/console.php), so no second worker picks the
     * import up while it is still running. Only enforced where the pcntl
     * extension is available.
     */
    public int $timeout = 600;

    /**
     * The relation columns (Redmine's IssueImport relation_* fields) and
     * the relation each makes: [stored type, whether the row is its target
     * rather than its source].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const array RELATION_COLUMNS = [
        'relation_relates' => ['relates', false],
        'relation_blocks' => ['blocks', false],
        'relation_blocked' => ['blocks', true],
        'relation_duplicates' => ['duplicates', false],
        'relation_duplicated' => ['duplicates', true],
        'relation_precedes' => ['precedes', false],
        'relation_follows' => ['follows', false],
        'relation_copied_to' => ['copied_to', false],
        'relation_copied_from' => ['copied_to', true],
    ];

    /** @var array<int, Collection<int, CustomField>> tracker id => the custom fields a row of that tracker may set */
    private array $customFieldsByTracker = [];

    /*
     * State of one run of handle(), shared by the passes below.
     */

    private IssueService $issueService;

    /** @var list<string> */
    private array $header = [];

    /** @var list<list<string|null>> */
    private array $rows = [];

    /** @var array<string, mixed> */
    private array $mapping = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    private bool $useUniqueId = false;

    /** @var array<string, int> unique id => row index */
    private array $indexByUniqueId = [];

    /** @var array<int, int> row index => the id of the issue it created */
    private array $issueIdByIndex = [];

    /** @var array<int, true> */
    private array $failedIndexes = [];

    /** @var array<int, true> rows being imported, to catch a parent cycle */
    private array $inProgress = [];

    /** @var list<array{row: int, message: string}> */
    private array $errors = [];

    public function __construct(
        private readonly IssueImport $import,
    ) {}

    public function handle(IssueService $issueService): void
    {
        $this->import->update(['status' => ImportStatus::Processing]);

        $disk = Storage::disk('local');

        if (! $disk->exists($this->import->file_path)) {
            $this->import->update([
                'status' => ImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => __('アップロードされたファイルが見つかりません。')]],
            ]);

            return;
        }

        ['header' => $header, 'rows' => $rows] = CsvReader::read($disk->path($this->import->file_path));

        $this->import->update(['total_rows' => count($rows)]);

        $mapping = $this->import->column_mapping;

        $defaults = [
            // Redmine's IssueImport#allowed_target_trackers: the trackers the
            // importing user may create issues with.
            'trackers' => $allowedTrackers = Issue::allowedTargetTrackers($this->import->project, $this->import->user),
            'tracker' => $allowedTrackers->first(),
            'status' => IssueStatus::query()->orderBy('position')->first(),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->first(),
            // Computed once rather than per row — the permission doesn't
            // vary by row, and it gates whether a mapped is_private column
            // is honored at all (same as the manual form hiding the
            // checkbox entirely for users who lack this permission).
            'canSetPrivate' => $this->import->user->can('setPrivate', [Issue::class, $this->import->project]),
            // Same reasoning, gating whether an unmatched category/version
            // name gets auto-created rather than left unset — matches
            // Redmine's IssueImport#create_categories?/#create_versions?,
            // which likewise require both the opt-in checkbox AND the
            // permission, not either alone.
            'createCategories' => ($this->import->column_mapping['create_categories'] ?? false)
                && $this->import->user->can('create', [IssueCategory::class, $this->import->project]),
            'createVersions' => ($this->import->column_mapping['create_versions'] ?? false)
                && $this->import->user->can('create', [Version::class, $this->import->project]),
            // For the assigned_to column's keyword resolution (Redmine's
            // Principal.detect_by_keyword over issue.assignable_users) —
            // computed once, not per row.
            'projectMembers' => User::query()->whereHas('memberships', fn ($query) => $query->where('project_id', $this->import->project_id))->get(),
        ];

        $this->header = $header;
        $this->rows = $rows;
        $this->mapping = $mapping;
        $this->defaults = $defaults;
        $this->issueService = $issueService;
        $this->useUniqueId = ($mapping['unique_id'] ?? '') !== '';

        $this->indexUniqueIds();

        foreach (array_keys($rows) as $index) {
            $this->importRow($index);
        }

        foreach ($this->issueIdByIndex as $index => $issueId) {
            $this->buildRelations($index, $issueId);
        }

        usort($this->errors, fn (array $a, array $b) => $a['row'] <=> $b['row']);

        $this->import->update([
            'status' => ImportStatus::Completed,
            'processed_rows' => count($rows),
            'imported_count' => count($this->issueIdByIndex),
            'failed_count' => count($this->failedIndexes),
            'errors' => $this->errors,
        ]);
    }

    /**
     * Pass 0: which row holds each unique id. Every row sharing a unique id
     * with another fails, since a reference to it would be ambiguous.
     */
    private function indexUniqueIds(): void
    {
        if (! $this->useUniqueId) {
            return;
        }

        $indexesByUniqueId = [];

        foreach (array_keys($this->rows) as $index) {
            $uniqueId = $this->mapped($this->record($index), $this->mapping, 'unique_id');

            if ($uniqueId !== null && $uniqueId !== '') {
                $indexesByUniqueId[$uniqueId][] = $index;
            }
        }

        foreach ($indexesByUniqueId as $uniqueId => $indexes) {
            if (count($indexes) === 1) {
                $this->indexByUniqueId[(string) $uniqueId] = $indexes[0];

                continue;
            }

            foreach ($indexes as $index) {
                $this->fail($index, __('一意なID「:id」が複数の行で使われています。', ['id' => $uniqueId]));
            }
        }
    }

    /**
     * Pass 1: creates the row's issue, first creating the row its parent
     * column names by unique id (so a parent may come later in the file).
     * A parent row that is missing or failed, or a chain of parents that
     * comes back to the row, fails the row instead of importing it without
     * its parent.
     */
    private function importRow(int $index): void
    {
        if (isset($this->issueIdByIndex[$index]) || isset($this->failedIndexes[$index])) {
            return;
        }

        if (isset($this->inProgress[$index])) {
            $this->fail($index, __('親課題の指定が循環しています。'));

            return;
        }

        $this->inProgress[$index] = true;

        try {
            $record = $this->record($index);
            $parentId = null;
            $parentUniqueId = $this->parentUniqueId($record);

            if ($parentUniqueId !== null) {
                $parentIndex = $this->indexByUniqueId[$parentUniqueId] ?? null;

                if ($parentIndex === null) {
                    throw new RuntimeException(__('一意なID「:id」の親課題の行が見つかりません。', ['id' => $parentUniqueId]));
                }

                $this->importRow($parentIndex);

                if (isset($this->failedIndexes[$index])) {
                    return;
                }

                $parentId = $this->issueIdByIndex[$parentIndex]
                    ?? throw new RuntimeException(__('親課題の行(:row行目)を取り込めなかったため、この行も取り込みません。', ['row' => $parentIndex + 2]));
            }

            $attributes = $this->mapRowToAttributes($record, $this->mapping, $this->defaults, skipParent: $parentUniqueId !== null);

            if ($parentId !== null) {
                $attributes['parent_id'] = $parentId;
            }

            // Redmine's IssueImport sets the row through safe_attributes= as
            // the importing user: the workflow's read-only fields and those
            // the tracker disables are ignored, a required one left blank
            // fails the row.
            // Redmine's import "notifications" setting (issue.notify): off unless chosen, so a
            // large import does not mail everyone involved once per row.
            $create = fn () => $this->issueService->create(
                $attributes,
                $this->import->user,
                $this->customFieldData($record, $this->mapping, $attributes['tracker_id']),
                applyFieldRules: true,
            );
            $issue = ($this->import->column_mapping['notifications'] ?? false) ? $create() : MailSuppression::during($create);

            $this->issueIdByIndex[$index] = $issue->id;
        } catch (Throwable $e) {
            $this->fail($index, $e->getMessage());
        } finally {
            unset($this->inProgress[$index]);
            $this->import->increment('processed_rows');
        }
    }

    /**
     * The parent column's value when it names another row by unique id —
     * only with a unique id column mapped, and never for "#123", which is
     * an existing issue as without one (Redmine's IssueImport).
     *
     * @param  array<string, mixed>  $record
     */
    private function parentUniqueId(array $record): ?string
    {
        $parentRef = $this->mapped($record, $this->mapping, 'parent');

        if (! $this->useUniqueId || $parentRef === null || $parentRef === '' || str_starts_with($parentRef, '#')) {
            return null;
        }

        return $parentRef;
    }

    /**
     * Pass 2: the row's relation columns — Redmine's
     * IssueImport#build_relations. Each column holds comma-separated
     * references: "#123" is an existing issue; with a unique id column
     * mapped, anything else names a row by unique id; otherwise a bare
     * number is an existing issue, as the parent column reads it. A
     * precedes/follows reference may end in a delay ("#123 3d"). Every
     * relation is created through IssueService::addRelation() with the
     * importing user's view of the target; one that cannot be made is
     * reported against the row, which stays imported.
     */
    private function buildRelations(int $index, int $issueId): void
    {
        $record = $this->record($index);
        $issue = null;

        foreach (self::RELATION_COLUMNS as $column => [$type, $reversed]) {
            $cell = $this->mapped($record, $this->mapping, $column);

            if ($cell === null || $cell === '') {
                continue;
            }

            foreach (explode(',', $cell) as $declaration) {
                $declaration = trim($declaration);

                if ($declaration === '') {
                    continue;
                }

                try {
                    $issue ??= Issue::query()->findOrFail($issueId);
                    [$otherId, $delay] = $this->relationTarget($declaration, in_array($type, ['precedes', 'follows'], true));
                    [$from, $toId] = $reversed ? [Issue::query()->findOrFail($otherId), $issue->id] : [$issue, $otherId];

                    if ($reversed && ! $from->isVisibleTo($this->import->user)) {
                        throw new RuntimeException(__('課題が見つかりません。'));
                    }

                    $this->issueService->addRelation($from, $toId, $type, $delay, $this->import->user);
                } catch (Throwable $e) {
                    $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                    $this->errors[] = ['row' => $index + 2, 'message' => __('関連「:declaration」を作成できません: :message', ['declaration' => $declaration, 'message' => $message])];
                }
            }
        }
    }

    /**
     * The issue id and delay one relation reference stands for.
     *
     * @return array{0: int, 1: int|null}
     */
    private function relationTarget(string $declaration, bool $allowsDelay): array
    {
        if (preg_match('/\A(?<ref>(?<is_id>#)?(?<id>\d+)|.+?)(?:\s+(?<delay>-?\d+)d)?\z/u', $declaration, $match) !== 1
            || (($match['delay'] ?? '') !== '' && ! $allowsDelay)) {
            throw new RuntimeException(__('書式が正しくありません。'));
        }

        $delay = ($match['delay'] ?? '') !== '' ? (int) $match['delay'] : null;

        if (($match['is_id'] ?? '') !== '' || (! $this->useUniqueId && ($match['id'] ?? '') !== '')) {
            return [(int) $match['id'], $delay];
        }

        if (! $this->useUniqueId) {
            throw new RuntimeException(__('書式が正しくありません。'));
        }

        $otherIndex = $this->indexByUniqueId[$match['ref']] ?? null;

        if ($otherIndex === null) {
            throw new RuntimeException(__('一意なID「:id」の行が見つかりません。', ['id' => $match['ref']]));
        }

        return [
            $this->issueIdByIndex[$otherIndex] ?? throw new RuntimeException(__(':row行目を取り込めなかったため、関連を作成できません。', ['row' => $otherIndex + 2])),
            $delay,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(int $index): array
    {
        return array_combine($this->header, array_pad(array_slice($this->rows[$index], 0, count($this->header)), count($this->header), null));
    }

    private function fail(int $index, string $message): void
    {
        $this->failedIndexes[$index] = true;
        $this->errors[] = ['row' => $index + 2, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function mapRowToAttributes(array $record, array $mapping, array $defaults, bool $skipParent = false): array
    {
        $subject = trim((string) $this->mapped($record, $mapping, 'subject'));

        if ($subject === '') {
            throw new RuntimeException(__('題名が空です。'));
        }

        $trackerName = $this->mapped($record, $mapping, 'tracker');
        $tracker = ($trackerName !== null ? $defaults['trackers']->first(fn (Tracker $candidate) => $candidate->name === $trackerName) : null) ?? $defaults['tracker'];

        $statusName = $this->mapped($record, $mapping, 'status');
        $status = ($statusName !== null ? IssueStatus::query()->where('name', $statusName)->first() : null) ?? $defaults['status'];

        $priorityName = $this->mapped($record, $mapping, 'priority');
        $priority = ($priorityName !== null
            ? Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('name', $priorityName)->first()
            : null) ?? $defaults['priority'];

        if ($tracker === null || $status === null || $priority === null) {
            throw new RuntimeException(__('トラッカー・ステータス・優先度のいずれかを特定できません。'));
        }

        // Scoped to project members — a login/name/email matching some
        // other user in the system (unrelated to this project) should not
        // be able to receive an assignment via a crafted CSV row. Matches
        // Redmine's Principal.detect_by_keyword precedence: login, then
        // email, then (a two-word value) firstname+lastname, then the
        // full display name.
        $assigneeKeyword = $this->mapped($record, $mapping, 'assigned_to');
        $assignee = $assigneeKeyword !== null ? $this->resolveAssignee($assigneeKeyword, $defaults['projectMembers']) : null;

        // Redmine matches the column against the assignable principals, so
        // with issue_group_assignment on it may name an assignable group.
        $assigneeGroup = $assigneeKeyword !== null && $assignee === null && Issue::groupAssignmentEnabled()
            ? $this->import->project->assignableGroups()->first(fn (Group $group) => strcasecmp($group->name, trim($assigneeKeyword)) === 0)
            : null;

        // A category/version name that matches nothing is auto-created
        // when the importing user opted in (and holds the permission a
        // manual creation would also require — see $defaults above); the
        // row is otherwise never failed over this, since neither field is
        // required to create an issue.
        $categoryName = $this->mapped($record, $mapping, 'category');
        $category = $categoryName !== null
            ? $this->import->project->issueCategories()->where('name', $categoryName)->first()
            : null;

        if ($category === null && $categoryName !== null && $defaults['createCategories']) {
            $category = $this->import->project->issueCategories()->create(['name' => $categoryName]);
        }

        $versionName = $this->mapped($record, $mapping, 'fixed_version');
        $version = $versionName !== null
            ? $this->import->project->versions()->where('name', $versionName)->first()
            : null;

        if ($version === null && $versionName !== null && $defaults['createVersions']) {
            $version = $this->import->project->versions()->create(['name' => $versionName]);
        }

        // Unlike category/version, an explicitly mapped parent reference
        // that can't be resolved fails the row — silently dropping it
        // would leave what was meant to be a subtask parented incorrectly
        // (or not at all) with no indication anything went wrong. Scoped
        // to this project, matching the manual form's parent_id rule.
        $parentRef = $skipParent ? null : $this->mapped($record, $mapping, 'parent');
        $parentRef = $parentRef === '' ? null : $parentRef;
        $parent = $parentRef !== null
            ? Issue::query()->where('project_id', $this->import->project_id)->visibleTo($this->import->user, $this->import->project)->find((int) ltrim($parentRef, '#'))
            : null;

        if ($parentRef !== null && $parent === null) {
            throw new RuntimeException(__('親課題 :issue が見つかりません。', ['issue' => $parentRef]));
        }

        // Only honored when the importing user actually has permission to
        // set issues private — a mapped column can't grant a permission
        // the manual form wouldn't offer them either.
        $isPrivateRaw = $this->mapped($record, $mapping, 'is_private');
        $isPrivate = $defaults['canSetPrivate'] && $isPrivateRaw !== null
            ? filter_var($isPrivateRaw, FILTER_VALIDATE_BOOLEAN)
            : false;

        return [
            'project_id' => $this->import->project_id,
            'tracker_id' => $tracker->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'subject' => $subject,
            'description' => $this->mapped($record, $mapping, 'description'),
            'assigned_to_id' => $assignee?->id,
            'assigned_to_group_id' => $assigneeGroup?->id,
            'category_id' => $category?->id,
            // Left out when the row names no version, so the project's
            // default version applies (Redmine's IssueImport).
            ...($version !== null ? ['fixed_version_id' => $version->id] : []),
            'parent_id' => $parent?->id,
            'is_private' => $isPrivate,
            'start_date' => $this->mapped($record, $mapping, 'start_date') ?: null,
            'due_date' => $this->mapped($record, $mapping, 'due_date') ?: null,
            'done_ratio' => (int) ($this->mapped($record, $mapping, 'done_ratio') ?: 0),
            'estimated_hours' => Hours::parse($this->mapped($record, $mapping, 'estimated_hours')),
        ];
    }

    /**
     * Matches a CSV "assigned_to" cell against this project's members,
     * Redmine's Principal.detect_by_keyword: the login, then the email
     * address (both case-insensitive), then — for a value with a space —
     * firstname+lastname, then the full display name. The first candidate
     * to match wins, same order as Redmine tries them.
     *
     * @param  Collection<int, User>  $members
     */
    private function resolveAssignee(string $keyword, Collection $members): ?User
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return null;
        }

        $byLogin = $members->first(fn (User $user) => strcasecmp($user->login, $keyword) === 0);

        if ($byLogin !== null) {
            return $byLogin;
        }

        $byEmail = $members->first(fn (User $user) => strcasecmp($user->email, $keyword) === 0);

        if ($byEmail !== null) {
            return $byEmail;
        }

        if (str_contains($keyword, ' ')) {
            [$firstname, $lastname] = explode(' ', $keyword, 2);
            $byFullName = $members->first(fn (User $user) => strcasecmp((string) $user->firstname, $firstname) === 0 && strcasecmp((string) $user->lastname, $lastname) === 0);

            if ($byFullName !== null) {
                return $byFullName;
            }
        }

        return $members->first(fn (User $user) => strcasecmp($user->displayName(), $keyword) === 0);
    }

    /**
     * The custom field values of a row, keyed by field id — Redmine's
     * IssueImport#build_object over `cf_<id>` columns. Only the fields the
     * importing user may see and edit on an issue of this tracker count
     * (worked out for that user, not for whoever is signed in: a queued job
     * has nobody). A mapped, non-empty cell is read like a keyword (an
     * option by its label, a multiple field as a comma-separated list; an
     * unknown label leaves the value empty, as in Redmine); otherwise the
     * field's default value applies. The values are then validated like the
     * issue form's, so a required field left empty fails the row.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     * @return array<int, mixed>
     *
     * @throws ValidationException
     */
    private function customFieldData(array $record, array $mapping, int $trackerId): array
    {
        $project = $this->import->project;
        $user = $this->import->user;

        $fields = $this->customFieldsByTracker[$trackerId] ??= (new Issue)
            ->forceFill(['project_id' => $project->id, 'tracker_id' => $trackerId])
            ->setRelation('project', $project)
            ->relevantCustomFields($user)
            ->filter(fn (CustomField $field) => $field->editableBy($user))
            ->values();

        $values = [];

        foreach ($fields as $field) {
            $cell = $this->mapped($record, $mapping, "cf_{$field->id}");

            if ($cell === null || $cell === '') {
                $default = $field->defaultValue();

                if ($default !== null && $default !== '') {
                    $values[$field->id] = $field->multiple ? [$default] : $default;
                }

                continue;
            }

            $values[$field->id] = $field->multiple
                ? collect(explode(',', $cell))->map(fn (string $part) => trim($part))->filter(fn (string $part) => $part !== '')
                    ->map(fn (string $part) => $field->valueFromKeyword($part, $project))->filter(fn (?string $value) => $value !== null)->values()->all()
                : $field->valueFromKeyword($cell, $project);
        }

        Validator::make(
            ['customFieldValues' => $values],
            CustomField::formValidationRules($fields, null, $project),
            attributes: $fields->flatMap(fn (CustomField $field) => [
                "customFieldValues.{$field->id}" => $field->name,
                "customFieldValues.{$field->id}.*" => $field->name,
            ])->all(),
        )->validate();

        return $values;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     */
    private function mapped(array $record, array $mapping, string $field): ?string
    {
        $column = $mapping[$field] ?? null;

        if ($column === null || ! array_key_exists($column, $record)) {
            return null;
        }

        $value = $record[$column];

        return $value === null ? null : trim((string) $value);
    }

    public function failed(Throwable $e): void
    {
        $this->import->update(['status' => ImportStatus::Failed]);
    }
}
