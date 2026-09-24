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
use App\Support\Import\CsvReader;
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

    /** @var array<int, Collection<int, CustomField>> tracker id => the custom fields a row of that tracker may set */
    private array $customFieldsByTracker = [];

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
        $errors = [];
        $imported = 0;

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
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // the record's position in the file, the header being 1

            try {
                $record = array_combine($header, array_pad($row, count($header), null));

                $attributes = $this->mapRowToAttributes($record, $mapping, $defaults);

                $issueService->create(
                    $attributes,
                    $this->import->user,
                    $this->customFieldData($record, $mapping, $attributes['tracker_id']),
                );

                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }

            $this->import->increment('processed_rows');
        }

        $this->import->update([
            'status' => ImportStatus::Completed,
            'imported_count' => $imported,
            'failed_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function mapRowToAttributes(array $record, array $mapping, array $defaults): array
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

        // Scoped to project members — an email matching some other user in
        // the system (unrelated to this project) should not be able to
        // receive an assignment via a crafted CSV row.
        $assigneeEmail = $this->mapped($record, $mapping, 'assigned_to');
        $assignee = $assigneeEmail !== null
            ? User::query()
                ->whereHas('memberships', fn ($query) => $query->where('project_id', $this->import->project_id))
                ->where('email', $assigneeEmail)
                ->first()
            : null;

        // Redmine matches the column against the assignable principals, so
        // with issue_group_assignment on it may name an assignable group.
        $assigneeGroup = $assigneeEmail !== null && $assignee === null && Issue::groupAssignmentEnabled()
            ? $this->import->project->assignableGroups()->first(fn (Group $group) => strcasecmp($group->name, trim($assigneeEmail)) === 0)
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
        $parentRef = $this->mapped($record, $mapping, 'parent');
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
            'fixed_version_id' => $version?->id,
            'parent_id' => $parent?->id,
            'is_private' => $isPrivate,
            'start_date' => $this->mapped($record, $mapping, 'start_date') ?: null,
            'due_date' => $this->mapped($record, $mapping, 'due_date') ?: null,
            'done_ratio' => (int) ($this->mapped($record, $mapping, 'done_ratio') ?: 0),
        ];
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
