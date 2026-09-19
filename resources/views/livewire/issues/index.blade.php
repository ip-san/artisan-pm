<?php

use App\Concerns\SelectsPageSize;
use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\ReordersColumns;
use App\Enums\EnumerationType;
use App\Enums\FilterOperator;
use App\Enums\IssueTimeEntryDisposition;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\Watcher;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\ListQueryString;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\DefaultIssueQuery;
use App\Support\Query\ListDefaults;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;
    use ReordersColumns;
    use SelectsPageSize;
    use WithPagination;

    /**
     * Native columns selectable for display/CSV export. Custom fields
     * join these in availableColumns(), keyed cf_{id} — the same key
     * convention the filter engine uses.
     *
     * @var array<string, string>
     */
    public const DISPLAY_COLUMNS = [
        'tracker_id' => 'トラッカー',
        'status_id' => 'ステータス',
        'priority_id' => '優先度',
        'subject' => '題名',
        'category_id' => 'カテゴリ',
        'assigned_to_id' => '担当者',
        'author_id' => '作成者',
        'fixed_version_id' => '対象バージョン',
        'start_date' => '開始日',
        'due_date' => '期日',
        'created_at' => '作成日',
        'done_ratio' => '進捗率',
        'relations' => '関連するチケット',
        'attachments' => '添付ファイル',
        'watchers' => 'ウォッチャー',
        'estimated_hours' => '予定工数',
        'total_estimated_hours' => '合計予定工数',
        'estimated_remaining_hours' => '残り工数',
        'spent_hours' => '作業時間',
        'total_spent_hours' => '合計作業時間',
        'project_id' => 'プロジェクト',
        'parent_id' => '親課題',
        'updated_at' => '更新日',
        'closed_on' => '終了日',
        'last_updated_by' => '最終更新者',
        'is_private' => '非公開',
        'description' => '説明',
        'last_notes' => '最新のコメント',
    ];

    /**
     * Columns shown on a row of their own under the issue (Redmine's
     * `inline: false`): long text does not belong in a table cell.
     *
     * @var array<int, string>
     */
    public const array BLOCK_COLUMNS = ['description', 'last_notes'];

    /**
     * The hour columns computed from other data rather than stored on the
     * issue: they cannot be sorted in SQL, so their headings are not
     * sort buttons.
     *
     * @var array<int, string>
     */
    public const array COMPUTED_HOUR_COLUMNS = ['total_estimated_hours', 'estimated_remaining_hours', 'spent_hours', 'total_spent_hours', 'parent_id', 'last_updated_by', 'is_private', 'description', 'last_notes'];

    /**
     * Matches issues/show.blade.php's RELATION_LABELS wording exactly —
     * duplicated rather than extracted, since that copy also needs the
     * reversed-journal-key mapping this one doesn't (a different use
     * case: rendering journal history vs. a flat CSV/column cell).
     *
     * @var array<string, array{from: string, to: string}>
     */
    private const array RELATION_LABELS = [
        'relates' => ['from' => '関連', 'to' => '関連'],
        'blocks' => ['from' => 'ブロックする', 'to' => 'ブロックされている'],
        'duplicates' => ['from' => '重複する', 'to' => '重複されている'],
        'precedes' => ['from' => '先行', 'to' => '先行'],
        'follows' => ['from' => '後続', 'to' => '後続'],
        'copied_to' => ['from' => 'コピー先', 'to' => 'コピー元'],
    ];

    public Project $project;

    #[Url]
    public string $statusFilter = 'open';

    /** @var array<int, string> */
    #[Url]
    public array $columns = [];

    public string $csvEncoding = 'UTF-8';

    public string $csvSeparator = ',';

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    /**
     * Additional sort levels applied after the primary sortKey/
     * sortDirection above — Redmine allows sorting by up to 3 columns
     * total, so these two cover the remaining 2. Set via the "並べ替え"
     * panel rather than header clicks, which only ever control the
     * primary key.
     */
    #[Url]
    public ?string $sortKey2 = null;

    #[Url]
    public string $sortDirection2 = 'asc';

    #[Url]
    public ?string $sortKey3 = null;

    #[Url]
    public string $sortDirection3 = 'asc';

    #[Url]
    public ?string $groupBy = null;

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /** @var array<int, int> */
    public array $selected = [];

    public ?int $bulkPriorityId = null;

    public ?int $bulkAssignedToId = null;

    public ?int $bulkFixedVersionId = null;

    public ?int $bulkStatusId = null;

    public ?int $bulkDoneRatio = null;

    public ?int $bulkTrackerId = null;

    public ?int $bulkCategoryId = null;

    public string $bulkStartDate = '';

    public string $bulkDueDate = '';

    /** '' leaves it, 'yes' makes the issues private, 'no' public. */
    public string $bulkIsPrivate = '';

    public ?int $bulkParentId = null;

    public string $bulkComment = '';

    /**
     * Fields the bulk edit sets to "none" (only these three can be blank).
     *
     * @var array<int, string>
     */
    public array $bulkClear = [];

    public ?int $bulkMoveToProjectId = null;

    public ?int $bulkMoveToTrackerId = null;

    public ?int $bulkCopyToProjectId = null;

    public ?int $bulkCopyToTrackerId = null;

    public bool $bulkCopyAttachments = true;

    public bool $bulkCopyWatchers = true;

    public bool $bulkCopySubtasks = true;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        $this->project = $project;

        $this->applyDefaultQuery();

        // Only applied when the URL didn't already supply columns (a fresh
        // visit, no saved query loaded) — matches Redmine's
        // Setting.issue_list_default_columns.
        if ($this->columns === []) {
            $this->columns = Setting::get(
                'issue_list_default_columns',
                ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']
            );
        }
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project));
    }

    /**
     * The project plus, with display_subprojects_issues on, the subprojects
     * whose issues the list also shows.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function scopeProjects(): Collection
    {
        return SubprojectScope::projectsForIssues($this->project, auth()->user());
    }

    /**
     * @return Builder<Issue>
     */
    private function filteredIssuesQuery(): Builder
    {
        $query = Issue::query()
            ->visibleToAcrossProjects(auth()->user(), $this->scopeProjects)
            ->with(['tracker', 'status', 'priority', 'category', 'assignedTo', 'author', 'fixedVersion'])
            ->when(
                collect($this->columns)->contains(fn (string $column) => str_starts_with($column, 'cf_')),
                fn (Builder $q) => $q->with('customFieldValues.customField')
            )
            ->when(in_array('relations', $this->columns, true), fn (Builder $q) => $q->with(['relationsFrom', 'relationsTo']))
            ->when(in_array('attachments', $this->columns, true), fn (Builder $q) => $q->with('media'))
            ->when(in_array('watchers', $this->columns, true), fn (Builder $q) => $q->with('watchers.user'))
            ->when(in_array('project_id', $this->columns, true), fn (Builder $q) => $q->with('project'))
            ->when(in_array('last_updated_by', $this->columns, true), fn (Builder $q) => $q->with('lastJournal.user'))
            ->when(in_array('last_notes', $this->columns, true), fn (Builder $q) => $q->with('lastNotesJournal'))
            ->when(array_intersect(['spent_hours', 'total_spent_hours', 'total_estimated_hours'], $this->columns) !== [], fn (Builder $q) => $q->withCount('children'))
            ->when(array_intersect(['spent_hours', 'total_spent_hours'], $this->columns) !== [], fn (Builder $q) => $q->withSum('timeEntries', 'hours'));

        if ($this->statusFilter !== 'all') {
            $isClosed = $this->statusFilter === 'closed';
            $query->whereHas('status', fn ($q) => $q->where('is_closed', $isClosed));
        }

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        $sortCriteria = $this->sortCriteria();

        if ($sortCriteria !== []) {
            $query = $this->engine->applySort($query, $sortCriteria);
        } else {
            $query->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Up to 3 [key, direction] pairs — Redmine's own cap on how many
     * columns an issue list can be sorted by. The 2nd/3rd levels are only
     * meaningful once a primary key is set.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function sortCriteria(): array
    {
        return ListQueryString::sortCriteria(
            $this->sortKey, $this->sortDirection,
            $this->sortKey2, $this->sortDirection2,
            $this->sortKey3, $this->sortDirection3,
        );
    }

    /**
     * The Atom feed link carries the list's current filters and sort, so
     * the feed shows the same issues (Redmine's issues.atom honours the
     * query the same way).
     */
    #[Computed]
    public function atomUrl(): string
    {
        return route('issues.atom', $this->project).'?'.http_build_query([
            'key' => auth()->user()?->atomKey(),
            'statusFilter' => $this->statusFilter,
            ...ListQueryString::toQueryParameters(
                $this->activeFilterKeys, $this->filterOperators, $this->filterValues,
                $this->sortKey, $this->sortDirection,
                $this->sortKey2, $this->sortDirection2,
                $this->sortKey3, $this->sortDirection3,
            ),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Issue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return $this->filteredIssuesQuery()->paginate($this->pageSize((int) Setting::get('default_issues_per_page', 25)));
    }

    /**
     * The rows shown per group are limited to the current page (grouping
     * the entire filtered set would defeat the point of paginating), but
     * the group header counts come from groupTotals() below, which runs a
     * real SQL aggregate over the full filtered set — so counts are
     * accurate even though a group's rows may span multiple pages.
     *
     * @return Collection<string, EloquentCollection<int, Issue>>
     */
    #[Computed]
    public function groupedIssues(): Collection
    {
        $pageIssues = $this->issues->getCollection();

        if ($this->groupBy === null) {
            return collect(['' => $pageIssues]);
        }

        return $pageIssues->groupBy(fn (Issue $issue) => $this->columnValue($issue, $this->groupBy));
    }

    /**
     * True per-group counts across the entire filtered result set (not
     * just the current page), computed via a SQL GROUP BY. Offered for the
     * groupBy select's native options (status/tracker/priority/assigned
     * to, all plain FK columns on `issues`) and for single-value custom
     * fields (delegated to groupTotalsByCustomField()) — $groupBy is
     * checked against the known column whitelist, or resolved to a real
     * CustomField, before it ever reaches raw SQL.
     *
     * @return Collection<string, array{count: int, estimated: float, spent: float}>
     */
    #[Computed]
    public function groupTotals(): Collection
    {
        if ($this->groupBy === null) {
            return collect();
        }

        if (str_starts_with($this->groupBy, 'cf_')) {
            $field = $this->groupableCustomField();

            return $field === null ? collect() : $this->groupTotalsByCustomField($field);
        }

        if (! array_key_exists($this->groupBy, self::DISPLAY_COLUMNS)) {
            return collect();
        }

        $column = $this->groupBy;
        $options = $this->engine->field($column)?->options() ?? [];
        $nullLabel = $column === 'assigned_to_id' ? '未割当' : '';
        $resolveLabel = fn (mixed $rawKey) => ($rawKey === null || $rawKey === '')
            ? $nullLabel
            : ($options[$rawKey] ?? (string) $rawKey);

        $spentByGroup = TimeEntry::query()
            ->join('issues', 'time_entries.issue_id', '=', 'issues.id')
            ->whereIn('issues.id', $this->filteredIssuesQuery()->reorder()->select('issues.id'))
            ->selectRaw("issues.{$column} as group_key, SUM(time_entries.hours) as spent")
            ->groupBy("issues.{$column}")
            ->pluck('spent', 'group_key');

        return $this->filteredIssuesQuery()
            ->reorder()
            ->selectRaw("{$column} as group_key, COUNT(*) as total, COALESCE(SUM(estimated_hours), 0) as estimated")
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn ($row) => [
                $resolveLabel($row->group_key) => [
                    'count' => (int) $row->total,
                    'estimated' => (float) $row->estimated,
                    'spent' => (float) ($spentByGroup[$row->group_key] ?? 0),
                ],
            ]);
    }

    /**
     * Resolves $groupBy to an applicable, single-value custom field.
     * Multi-value fields (CustomField::$multiple) are excluded — a raw SQL
     * join against custom_field_values would multiply COUNT(*) for any
     * issue holding more than one value, so they're not offered as a
     * groupBy option in the first place (see the groupBy <select>).
     */
    private function groupableCustomField(): ?CustomField
    {
        $fieldId = (int) substr($this->groupBy ?? '', 3);

        return $this->projectIssueCustomFields
            ->first(fn (CustomField $field) => $field->id === $fieldId && ! $field->multiple);
    }

    /**
     * @return Collection<string, array{count: int, estimated: float, spent: float}>
     */
    private function groupTotalsByCustomField(CustomField $field): Collection
    {
        $storageColumn = $field->format()->storageColumn();
        $options = $field->format()->options($field);
        $resolveLabel = fn (mixed $rawKey) => ($rawKey === null || $rawKey === '')
            ? ''
            : ($options[$rawKey] ?? (string) $rawKey);

        // A closure join (rather than a plain where()) keeps this a true
        // LEFT JOIN: issues with no value for this field must still appear
        // in the '' group, which a post-join where() would silently drop.
        $issueMorphClass = (new Issue)->getMorphClass();
        $joinValueForField = function (\Illuminate\Database\Query\JoinClause $join) use ($field, $issueMorphClass): void {
            $join->on('custom_field_values.customized_id', '=', 'issues.id')
                ->where('custom_field_values.customized_type', $issueMorphClass)
                ->where('custom_field_values.custom_field_id', $field->id);
        };

        $spentByGroup = TimeEntry::query()
            ->join('issues', 'time_entries.issue_id', '=', 'issues.id')
            ->leftJoin('custom_field_values', $joinValueForField)
            ->whereIn('issues.id', $this->filteredIssuesQuery()->reorder()->select('issues.id'))
            ->selectRaw("custom_field_values.{$storageColumn} as group_key, SUM(time_entries.hours) as spent")
            ->groupBy("custom_field_values.{$storageColumn}")
            ->pluck('spent', 'group_key');

        return $this->filteredIssuesQuery()
            ->reorder()
            ->leftJoin('custom_field_values', $joinValueForField)
            ->selectRaw("custom_field_values.{$storageColumn} as group_key, COUNT(*) as total, COALESCE(SUM(issues.estimated_hours), 0) as estimated")
            ->groupBy("custom_field_values.{$storageColumn}")
            ->get()
            ->mapWithKeys(fn ($row) => [
                $resolveLabel($row->group_key) => [
                    'count' => (int) $row->total,
                    'estimated' => (float) $row->estimated,
                    'spent' => (float) ($spentByGroup[$row->group_key] ?? 0),
                ],
            ]);
    }

    /**
     * Estimated and spent hour sums across the entire filtered set (not
     * just the current page) — matches Redmine's issue list totals
     * (Setting.issue_list_default_totals), shown for both fixed rather
     * than made configurable. When grouped, these are already summed
     * from groupTotals() rather than re-querying.
     *
     * `remaining` (estimate not yet worked off) is summed only when the
     * issue_list_default_totals setting asks for it.
     *
     * @return array{estimated: float, spent: float, remaining: float}
     */
    #[Computed]
    public function listTotals(): array
    {
        $remaining = in_array('estimated_remaining_hours', ListDefaults::issueTotals(), true)
            ? (float) $this->filteredIssuesQuery()->reorder()->sum(DB::raw('COALESCE(issues.estimated_hours, 0) * (100 - COALESCE(issues.done_ratio, 0)) / 100.0'))
            : 0.0;

        if ($this->groupBy !== null && $this->groupTotals->isNotEmpty()) {
            return [
                'estimated' => (float) $this->groupTotals->sum('estimated'),
                'spent' => (float) $this->groupTotals->sum('spent'),
                'remaining' => $remaining,
            ];
        }

        $estimated = (float) $this->filteredIssuesQuery()->reorder()->sum('estimated_hours');

        $spent = (float) TimeEntry::query()
            ->whereIn('issue_id', $this->filteredIssuesQuery()->reorder()->select('issues.id'))
            ->sum('hours');

        return ['estimated' => $estimated, 'spent' => $spent, 'remaining' => $remaining];
    }

    public function applyFilters(): void
    {
        $this->resetPage();
        unset($this->issues, $this->groupedIssues, $this->groupTotals);
    }

    public function sortBy(string $key): void
    {
        if ($this->sortKey === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGroupBy(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'manage_public_queries', $this->project);
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $this->project);

        $query = SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::Issue->value,
            'user_id' => auth()->id(),
            'project_id' => $this->project->id,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => $this->columns,
            'sort_criteria' => $this->sortCriteria(),
            'group_by' => $this->groupBy,
        ]);

        if ($visibility === QueryVisibility::Roles->value) {
            $query->roles()->sync($data['newQueryRoleIds']);
        }

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', 'クエリを保存しました。');
    }

    /**
     * Redmine's IssueQuery.default: a visit that names no filter, sort or
     * columns of its own opens on the user's, the project's or the site's
     * default query, in that order.
     */
    private function applyDefaultQuery(): void
    {
        if (request()->hasAny(['columns', 'groupBy', 'sortKey', 'activeFilterKeys', 'statusFilter', 'f'])) {
            return;
        }

        $query = DefaultIssueQuery::for(auth()->user(), $this->project);

        if ($query !== null) {
            $this->loadQuery($query->id);
        }
    }

    public function loadQuery(int $queryId): void
    {
        $query = SavedQuery::query()
            ->where(fn ($q) => $q->where('project_id', $this->project->id)->orWhereNull('project_id'))
            ->findOrFail($queryId);

        abort_unless($query->visibleTo(auth()->user()), 403);

        $this->activeFilterKeys = array_keys($query->filters);
        $this->filterOperators = [];
        $this->filterValues = [];

        foreach ($query->filters as $key => $filter) {
            $this->filterOperators[$key] = $filter['operator'];
            $this->filterValues[$key] = $filter['values'] ?? [];
        }

        $this->columns = $query->column_names;
        $this->groupBy = $query->group_by;

        $this->sortKey = null;
        $this->sortKey2 = null;
        $this->sortKey3 = null;

        $criteria = $query->sort_criteria ?? [];

        if (isset($criteria[0])) {
            [$this->sortKey, $this->sortDirection] = $criteria[0];
        }

        if (isset($criteria[1])) {
            [$this->sortKey2, $this->sortDirection2] = $criteria[1];
        }

        if (isset($criteria[2])) {
            [$this->sortKey3, $this->sortDirection3] = $criteria[2];
        }

        $this->resetPage();
        unset($this->issues, $this->groupedIssues, $this->groupTotals);
    }

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleIn($this->project, QueryType::Issue, auth()->user());
    }

    /**
     * Every selectable column: the native set plus one cf_{id} entry per
     * issue custom field that applies to this project. Also the label
     * source for table headers and CSV headers.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableColumns(): array
    {
        $customFieldLabels = $this->projectIssueCustomFields
            ->mapWithKeys(fn (CustomField $field) => ["cf_{$field->id}" => $field->name])
            ->all();

        return [...self::DISPLAY_COLUMNS, ...$customFieldLabels];
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function projectIssueCustomFields(): Collection
    {
        return CustomField::query()
            ->where('customized_type', \App\Enums\CustomizableType::Issue)
            ->with('projects')
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $field->appliesToProject($this->project))
            ->values();
    }

    /**
     * The non-empty values of one custom field column for an issue, each
     * with its field so the cell can render a Link-format value as an
     * anchor (the same <x-custom-field-value> the show pages use).
     *
     * @return Collection<int, array{field: CustomField, value: string}>
     */
    public function customFieldCellValues(Issue $issue, string $key): Collection
    {
        $fieldId = (int) substr($key, 3);

        return $issue->loadMissing('customFieldValues.customField')
            ->customFieldValues
            ->where('custom_field_id', $fieldId)
            ->map(fn ($value) => ['field' => $value->customField, 'value' => $value->value()])
            ->filter(fn (array $cell) => $cell['value'] !== null && $cell['value'] !== '')
            ->values();
    }

    public function columnValue(Issue $issue, string $key): string
    {
        if (str_starts_with($key, 'cf_')) {
            return $this->customFieldCellValues($issue, $key)->pluck('value')->join(', ');
        }

        return match ($key) {
            'tracker_id' => $issue->tracker->name,
            'status_id' => $issue->status->name,
            'priority_id' => $issue->priority->name,
            'subject' => $issue->subject,
            'category_id' => $issue->category?->name ?? 'なし',
            'assigned_to_id' => $issue->assignedTo?->name ?? '未割当',
            'author_id' => $issue->author->name,
            'fixed_version_id' => $issue->fixedVersion?->name ?? 'なし',
            'start_date' => $issue->start_date?->toDateString() ?? '',
            'due_date' => $issue->due_date?->toDateString() ?? '',
            'created_at' => $issue->created_at->toDateString(),
            'done_ratio' => "{$issue->done_ratio}%",
            // Relations join with ", " (Redmine's own IssueRelation#to_s
            // list separator); attachments/watchers join with "\n" (one
            // per line within the cell) — matches Redmine's own
            // inconsistency between csv_value's relations vs
            // attachments/watcher_users handling, ported as-is rather
            // than unified, so a diff against a real Redmine export
            // looks the same.
            'relations' => $issue->relationsFrom
                ->map(fn (IssueRelation $relation) => self::RELATION_LABELS[$relation->relation_type->value]['from']." #{$relation->issue_to_id}")
                ->concat($issue->relationsTo->map(
                    fn (IssueRelation $relation) => self::RELATION_LABELS[$relation->relation_type->value]['to']." #{$relation->issue_from_id}"
                ))
                ->join(', '),
            'attachments' => $issue->attachments()->map(fn ($media) => $media->file_name)->join("\n"),
            'watchers' => $issue->watchers->map(fn (Watcher $watcher) => $watcher->user->name)->join("\n"),
            'project_id' => $issue->project->name,
            'parent_id' => $issue->parent_id !== null ? "#{$issue->parent_id}" : '',
            'updated_at' => $issue->updated_at?->format('Y-m-d H:i') ?? '',
            'closed_on' => $issue->closed_on?->format('Y-m-d H:i') ?? '',
            'last_updated_by' => ($issue->lastJournal?->user ?? $issue->author)->name,
            'is_private' => $issue->is_private ? 'はい' : 'いいえ',
            'description' => (string) $issue->description,
            'last_notes' => (string) $issue->lastNotesJournal?->notes,
            'estimated_hours' => $issue->estimated_hours !== null ? \App\Support\Format\Hours::format((float) $issue->estimated_hours, false) : '',
            'total_estimated_hours' => $issue->estimated_hours !== null || ! $issue->isLeaf() ? \App\Support\Format\Hours::format($issue->totalEstimatedHours(), false) : '',
            'estimated_remaining_hours' => $issue->estimated_hours !== null ? \App\Support\Format\Hours::format($issue->estimatedRemainingHours(), false) : '',
            'spent_hours' => \App\Support\Format\Hours::format($issue->spentHours(), false),
            'total_spent_hours' => \App\Support\Format\Hours::format($issue->totalSpentHours(), false),
            default => '',
        };
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', [Issue::class, $this->project]);

        $columns = $this->columns;
        $query = $this->filteredIssuesQuery();
        // Re-validated against the allowlist here rather than trusted from
        // the live property, since these drive raw file-writing behavior.
        $encoding = in_array($this->csvEncoding, ['UTF-8', 'SJIS-win'], true) ? $this->csvEncoding : 'UTF-8';
        $separator = in_array($this->csvSeparator, [',', ';', "\t"], true) ? $this->csvSeparator : ',';

        return response()->streamDownload(function () use ($columns, $query, $encoding, $separator) {
            $handle = fopen('php://output', 'w');

            // A UTF-8 BOM lets Excel auto-detect the encoding instead of
            // mis-rendering non-ASCII text as mojibake — matches Redmine's
            // Redmine::Export::CSV, which does the same for UTF-8 exports.
            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $writeRow = function (array $row) use ($handle, $separator, $encoding): void {
                if ($encoding !== 'UTF-8') {
                    $row = array_map(fn (string $value) => mb_convert_encoding($value, $encoding, 'UTF-8'), $row);
                }

                fputcsv($handle, $row, $separator);
            };

            $writeRow(array_map(fn ($key) => $this->availableColumns[$key] ?? $key, $columns));

            $query->chunk(200, function ($chunk) use ($writeRow, $columns) {
                foreach ($chunk as $issue) {
                    $writeRow(array_map(fn ($key) => $this->columnValue($issue, $key), $columns));
                }
            });

            fclose($handle);
        }, "{$this->project->identifier}-issues.csv");
    }

    #[Computed]
    public function canBulkEdit(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'edit_issues', $this->project);
    }

    #[Computed]
    public function canBulkCopy(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'copy_issues', $this->project);
    }

    /**
     * @return EloquentCollection<int, Issue>
     */
    #[Computed]
    public function selectedIssues(): EloquentCollection
    {
        if ($this->selected === []) {
            return new EloquentCollection;
        }

        return Issue::query()
            ->whereIn('id', $this->selected)
            ->where('project_id', $this->project->id)
            ->with('status')
            ->get();
    }

    /**
     * Only offered when every selected issue currently shares the same
     * status — each issue's own workflow otherwise governs which
     * transitions are valid, so a mixed selection has no single common
     * dropdown that's guaranteed safe for all of them.
     *
     * @return Collection<int, IssueStatus>
     */
    #[Computed]
    public function bulkStatusOptions(): Collection
    {
        $issues = $this->selectedIssues;

        if ($issues->isEmpty()) {
            return collect();
        }

        $workflow = app(WorkflowService::class);

        // Each issue's own workflow governs its transitions, so a mixed
        // selection offers only the statuses every one of them may move to.
        $common = $issues
            ->map(fn (Issue $issue) => $workflow->allowedTransitions($issue, auth()->user())->pluck('id'))
            ->reduce(fn (?Collection $carry, Collection $ids) => $carry === null ? $ids : $carry->intersect($ids));

        return $workflow->allowedTransitions($issues->first(), auth()->user())->whereIn('id', $common->all())->values();
    }

    /**
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function bulkTrackers(): Collection
    {
        return $this->project->trackers()->orderBy('position')->get();
    }

    /**
     * @return Collection<int, IssueCategory>
     */
    #[Computed]
    public function bulkCategories(): Collection
    {
        return $this->project->issueCategories()->orderBy('name')->get();
    }

    #[Computed]
    public function priorities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get();
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->project->assignableUsers();
    }

    #[Computed]
    public function projectVersions(): Collection
    {
        return $this->project->versions;
    }

    public function applyBulkEdit(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('update', $issue);
        }

        // Scoped to this project so a crafted request can't pull in another
        // project's version, a non-member assignee, or a same-table
        // enumeration row that isn't actually a priority (mirrors the same
        // fix in issues/form.blade.php's single-issue save()).
        $data = $this->validate([
            'bulkPriorityId' => ['nullable', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'bulkAssignedToId' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
            'bulkFixedVersionId' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $this->project->id)],
            'bulkStatusId' => ['nullable', 'exists:issue_statuses,id'],
            'bulkDoneRatio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'bulkTrackerId' => ['nullable', Rule::in($this->bulkTrackers->pluck('id')->all())],
            'bulkCategoryId' => ['nullable', Rule::in($this->bulkCategories->pluck('id')->all())],
            'bulkStartDate' => ['nullable', 'date'],
            'bulkDueDate' => ['nullable', 'date'],
            'bulkIsPrivate' => ['nullable', Rule::in(['yes', 'no'])],
            'bulkParentId' => ['nullable', Rule::exists('issues', 'id')->where('project_id', $this->project->id), function (string $attribute, mixed $value, \Closure $fail) use ($issues): void {
                // A parent may not be one of the issues being edited, nor
                // anything below them (that would loop the tree).
                $forbidden = $issues->pluck('id')->merge($issues->flatMap(fn (Issue $issue) => $issue->descendantIds()));

                if ($forbidden->contains((int) $value)) {
                    $fail('選択した課題自身またはその子孫は親課題にできません。');
                }
            }],
            'bulkComment' => ['nullable', 'string'],
        ]);

        if ($data['bulkIsPrivate'] !== null && $data['bulkIsPrivate'] !== '') {
            foreach ($issues as $issue) {
                $this->authorize('setPrivateOn', $issue);
            }
        }

        if ($data['bulkParentId'] !== null) {
            $this->authorize('manageSubtasks', [Issue::class, $this->project]);
        }

        $clearable = array_values(array_intersect($this->bulkClear, ['assigned_to_id', 'fixed_version_id', 'category_id']));

        $changes = array_filter([
            'priority_id' => $data['bulkPriorityId'],
            'assigned_to_id' => $data['bulkAssignedToId'],
            'fixed_version_id' => $data['bulkFixedVersionId'],
            'status_id' => $data['bulkStatusId'],
            'done_ratio' => $data['bulkDoneRatio'],
            'tracker_id' => $data['bulkTrackerId'],
            'category_id' => $data['bulkCategoryId'],
            'start_date' => filled($data['bulkStartDate'] ?? null) ? $data['bulkStartDate'] : null,
            'due_date' => filled($data['bulkDueDate'] ?? null) ? $data['bulkDueDate'] : null,
            'is_private' => match ($data['bulkIsPrivate'] ?? '') {
                'yes' => true,
                'no' => false,
                default => null,
            },
            'parent_id' => $data['bulkParentId'],
        ], fn ($value) => $value !== null);

        foreach ($clearable as $field) {
            $changes[$field] = null;
        }

        if (isset($changes['status_id'])) {
            $targetStatus = IssueStatus::findOrFail($changes['status_id']);

            foreach ($issues as $issue) {
                $this->authorize('transitionTo', [$issue, $targetStatus]);
            }
        }

        foreach ($issues as $issue) {
            app(IssueService::class)->update($issue, $changes, auth()->user(), $this->bulkComment ?: null);
        }

        $count = $issues->count();

        $this->reset(['selected', 'bulkPriorityId', 'bulkAssignedToId', 'bulkFixedVersionId', 'bulkStatusId', 'bulkDoneRatio', 'bulkTrackerId', 'bulkCategoryId', 'bulkStartDate', 'bulkDueDate', 'bulkIsPrivate', 'bulkParentId', 'bulkComment', 'bulkClear']);
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を更新しました。");
    }

    /**
     * Right-click on a row: the clicked issue becomes the selection unless it
     * is already part of it, so the menu acts on the whole selection like
     * Redmine's context menu does.
     */
    public function openContextMenu(int $issueId): void
    {
        abort_unless($this->canBulkEdit, 403);

        $issue = Issue::query()->where('project_id', $this->project->id)->find($issueId);

        abort_if($issue === null, 404);

        if (! in_array($issue->id, array_map('intval', $this->selected), true)) {
            $this->selected = [(string) $issue->id];
        }

        unset($this->selectedIssues, $this->bulkStatusOptions);
    }

    /**
     * One quick change from the context menu, applied to the selection
     * through the same validation and authorization as the bulk form. The
     * value `none` clears a version, assignee or category; `me` assigns the
     * current user.
     */
    public function contextUpdate(string $field, string $value): void
    {
        $properties = [
            'status_id' => 'bulkStatusId',
            'tracker_id' => 'bulkTrackerId',
            'priority_id' => 'bulkPriorityId',
            'fixed_version_id' => 'bulkFixedVersionId',
            'assigned_to_id' => 'bulkAssignedToId',
            'category_id' => 'bulkCategoryId',
            'done_ratio' => 'bulkDoneRatio',
        ];

        abort_unless($this->canBulkEdit && isset($properties[$field]), 403);

        $this->reset(['bulkPriorityId', 'bulkAssignedToId', 'bulkFixedVersionId', 'bulkStatusId', 'bulkDoneRatio', 'bulkTrackerId', 'bulkCategoryId', 'bulkStartDate', 'bulkDueDate', 'bulkIsPrivate', 'bulkParentId', 'bulkComment', 'bulkClear']);

        if ($value === 'none') {
            abort_unless(in_array($field, ['assigned_to_id', 'fixed_version_id', 'category_id'], true), 422);
            $this->bulkClear = [$field];
        } else {
            $this->{$properties[$field]} = (int) ($value === 'me' && $field === 'assigned_to_id' ? auth()->id() : $value);
        }

        $this->applyBulkEdit();
    }

    /**
     * Same eligibility rule as the single-issue move: projects the user
     * actually holds add_issues on.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function bulkMoveTargetProjects(): Collection
    {
        return Project::query()
            ->where('id', '!=', $this->project->id)
            ->get()
            ->filter(fn (Project $candidate) => auth()->user()?->can('create', [Issue::class, $candidate]))
            ->values();
    }

    /**
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function bulkMoveTargetTrackers(): Collection
    {
        if ($this->bulkMoveToProjectId === null) {
            return collect();
        }

        $target = $this->bulkMoveTargetProjects->firstWhere('id', $this->bulkMoveToProjectId);

        return $target?->loadMissing('trackers')->trackers ?? collect();
    }

    public function applyBulkMove(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('move', $issue);
        }

        $data = $this->validate([
            'bulkMoveToProjectId' => ['required', Rule::in($this->bulkMoveTargetProjects->pluck('id')->all())],
            'bulkMoveToTrackerId' => ['required', Rule::in($this->bulkMoveTargetTrackers->pluck('id')->all())],
        ]);

        $targetProject = Project::findOrFail($data['bulkMoveToProjectId']);

        foreach ($issues as $issue) {
            app(IssueService::class)->moveToProject($issue, $targetProject, $data['bulkMoveToTrackerId'], auth()->user());
        }

        $count = $issues->count();

        $this->reset(['selected', 'bulkMoveToProjectId', 'bulkMoveToTrackerId']);
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を「{$targetProject->name}」へ移動しました。");
    }

    public bool $confirmingBulkDelete = false;

    public string $bulkTimeEntryTodo = 'nullify';

    public string $bulkReassignToId = '';

    /**
     * Hours logged directly against the selected issues — when there are
     * any, the delete asks what to do with them (Redmine's
     * issues/destroy.html.erb, which also offers a reassign target only when
     * the selection is within one project, as it always is here).
     */
    #[Computed]
    public function bulkDeleteHours(): float
    {
        if ($this->selectedIssues->isEmpty()) {
            return 0.0;
        }

        return (float) TimeEntry::query()->whereIn('issue_id', $this->selectedIssues->pluck('id'))->sum('hours');
    }

    public function applyBulkDelete(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('delete', $issue);
        }

        $disposition = IssueTimeEntryDisposition::Nullify;
        $reassignToId = null;

        if ($this->bulkDeleteHours > 0) {
            $disposition = IssueTimeEntryDisposition::tryFrom($this->bulkTimeEntryTodo);

            if ($disposition === null) {
                $this->addError('bulkTimeEntryTodo', '作業時間の扱いが不正です。');

                return;
            }

            if ($disposition === IssueTimeEntryDisposition::Reassign) {
                $reassignToId = $this->bulkReassignToId !== '' ? (int) $this->bulkReassignToId : null;

                // A target that is itself being deleted would strand the
                // entries again; refuse before anything is removed.
                if ($reassignToId === null || $issues->contains('id', $reassignToId)) {
                    $this->addError('bulkReassignToId', '削除しない、このプロジェクトの課題を指定してください。');

                    return;
                }
            }
        }

        $count = $issues->count();

        try {
            DB::transaction(function () use ($issues, $disposition, $reassignToId) {
                foreach ($issues as $issue) {
                    app(IssueService::class)->delete($issue, $disposition, $reassignToId);
                }
            });
        } catch (ValidationException $exception) {
            $this->addError('bulkReassignToId', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->reset('selected', 'confirmingBulkDelete', 'bulkTimeEntryTodo', 'bulkReassignToId');
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を削除しました。");
    }

    /**
     * Copy targets are simply every project the user holds add_issues on,
     * including the current one — unlike move, copying within the same
     * project is a normal Redmine use case (duplicate an issue as a
     * template).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function bulkCopyTargetProjects(): Collection
    {
        return Project::query()
            ->get()
            ->filter(fn (Project $candidate) => auth()->user()?->can('create', [Issue::class, $candidate]))
            ->values();
    }

    /**
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function bulkCopyTargetTrackers(): Collection
    {
        if ($this->bulkCopyToProjectId === null) {
            return collect();
        }

        $target = $this->bulkCopyTargetProjects->firstWhere('id', $this->bulkCopyToProjectId);

        return $target?->loadMissing('trackers')->trackers ?? collect();
    }

    public function applyBulkCopy(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        $data = $this->validate([
            'bulkCopyToProjectId' => ['required', Rule::in($this->bulkCopyTargetProjects->pluck('id')->all())],
            'bulkCopyToTrackerId' => ['required', Rule::in($this->bulkCopyTargetTrackers->pluck('id')->all())],
        ]);

        $targetProject = Project::findOrFail($data['bulkCopyToProjectId']);

        foreach ($issues as $issue) {
            $this->authorize('copy', [$issue, $targetProject]);
        }

        foreach ($issues as $issue) {
            app(IssueService::class)->copy(
                $issue,
                $targetProject,
                $data['bulkCopyToTrackerId'],
                auth()->user(),
                copyAttachments: $this->bulkCopyAttachments,
                copyWatchers: $this->bulkCopyWatchers,
                copySubtasks: $this->bulkCopySubtasks,
            );
        }

        $count = $issues->count();

        $this->reset(['selected', 'bulkCopyToProjectId', 'bulkCopyToTrackerId', 'bulkCopyAttachments', 'bulkCopyWatchers', 'bulkCopySubtasks']);
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を「{$targetProject->name}」へ複製しました。");
    }
}; ?>

<div x-data="{ menu: { open: false, x: 0, y: 0 }, showMenu(event, issueId) { const x = event.clientX, y = event.clientY; $wire.openContextMenu(issueId).then(() => { this.menu = { open: true, x: Math.min(x, window.innerWidth - 220), y: Math.min(y, window.innerHeight - 320) }; }); } }"
    x-on:click.window="menu.open = false" x-on:keydown.escape.window="menu.open = false">

    @if ($this->canBulkEdit && count($selected) > 0)
        <div x-show="menu.open" x-cloak x-on:click.stop x-bind:style="`left:${menu.x}px;top:${menu.y}px`" data-context-menu
            class="fixed z-50 w-52 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg">
            @if (count($selected) === 1)
                <a href="{{ route('issues.edit', [$project, $this->selectedIssues->first()]) }}" class="block px-3 py-1.5 text-gray-700 hover:bg-gray-100">編集</a>
            @else
                <a href="#bulk-edit-form" x-on:click="menu.open = false" class="block px-3 py-1.5 text-gray-700 hover:bg-gray-100">一括編集</a>
            @endif

            @foreach ([
                'status_id' => ['ステータス', $this->bulkStatusOptions, false],
                'tracker_id' => ['トラッカー', $this->bulkTrackers, false],
                'priority_id' => ['優先度', $this->priorities, false],
            ] as $menuField => [$menuLabel, $menuOptions, $menuNone])
                @if ($menuOptions->isNotEmpty())
                    <div class="group relative" wire:key="context-menu-{{ $menuField }}">
                        <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-gray-700 group-hover:bg-gray-100">{{ $menuLabel }} <span class="text-gray-400">›</span></span>
                        <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg group-hover:block">
                            @foreach ($menuOptions as $menuOption)
                                <button type="button" wire:click="contextUpdate('{{ $menuField }}', '{{ $menuOption->id }}')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">{{ $menuOption->name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            @foreach ([
                'fixed_version_id' => ['対象バージョン', $this->projectVersions(), '(なし)'],
                'assigned_to_id' => ['担当者', $this->projectMembers, '(未割当)'],
                'category_id' => ['カテゴリ', $this->bulkCategories, '(なし)'],
            ] as $menuField => [$menuLabel, $menuOptions, $menuNone])
                @if ($menuOptions->isNotEmpty() || $menuField === 'assigned_to_id')
                    <div class="group relative" wire:key="context-menu-{{ $menuField }}">
                        <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-gray-700 group-hover:bg-gray-100">{{ $menuLabel }} <span class="text-gray-400">›</span></span>
                        <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg group-hover:block">
                            @if ($menuField === 'assigned_to_id' && $this->projectMembers->contains('id', auth()->id()))
                                <button type="button" wire:click="contextUpdate('assigned_to_id', 'me')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">&lt;&lt; 自分 &gt;&gt;</button>
                            @endif
                            @foreach ($menuOptions as $menuOption)
                                <button type="button" wire:click="contextUpdate('{{ $menuField }}', '{{ $menuOption->id }}')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">{{ $menuOption->name }}</button>
                            @endforeach
                            <button type="button" wire:click="contextUpdate('{{ $menuField }}', 'none')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-500 hover:bg-gray-100">{{ $menuNone }}</button>
                        </div>
                    </div>
                @endif
            @endforeach

            <div class="group relative" wire:key="context-menu-done_ratio">
                <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-gray-700 group-hover:bg-gray-100">進捗率 <span class="text-gray-400">›</span></span>
                <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg group-hover:block">
                    @foreach (\App\Support\Issues\DoneRatioSteps::options() as $ratio)
                        <button type="button" wire:click="contextUpdate('done_ratio', '{{ $ratio }}')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">{{ $ratio }}%</button>
                    @endforeach
                </div>
            </div>

            @if ($this->canBulkCopy)
                <a href="#bulk-copy-form" x-on:click="menu.open = false" class="block border-t border-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-100">コピー</a>
            @endif
            @if (auth()->user()?->can('delete', $this->selectedIssues->first()))
                @if ($this->bulkDeleteHours > 0)
                    <button type="button" wire:click="$set('confirmingBulkDelete', true)" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-red-700 hover:bg-red-50">削除</button>
                @else
                    <button type="button" wire:click="applyBulkDelete" wire:confirm="選択した{{ count($selected) }}件の課題を削除します。この操作は取り消せません。よろしいですか?" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-red-700 hover:bg-red-50">削除</button>
                @endif
            @endif
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 課題</h1>
            <div class="mt-2 flex gap-3 text-sm">
                <button wire:click="$set('statusFilter', 'open')" class="{{ $statusFilter === 'open' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">未対応</button>
                <button wire:click="$set('statusFilter', 'closed')" class="{{ $statusFilter === 'closed' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">完了</button>
                <button wire:click="$set('statusFilter', 'all')" class="{{ $statusFilter === 'all' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">すべて</button>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ $this->atomUrl }}" class="text-xs text-orange-600 hover:underline">Atom</a>
            <a href="{{ route('issues.changes-atom', [$project, 'key' => auth()->user()?->atomKey()]) }}" class="text-xs text-orange-600 hover:underline">変更履歴(Atom)</a>
            <select wire:model="csvEncoding" title="文字コード" class="rounded-md border-gray-300 text-xs">
                <option value="UTF-8">UTF-8</option>
                <option value="SJIS-win">Shift_JIS</option>
            </select>
            <select wire:model="csvSeparator" title="区切り文字" class="rounded-md border-gray-300 text-xs">
                <option value=",">カンマ</option>
                <option value=";">セミコロン</option>
                <option value="{{ "\t" }}">タブ</option>
            </select>
            <button wire:click="exportCsv" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                CSVエクスポート
            </button>
            <a href="{{ route('issues.report', $project) }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                レポート
            </a>
            @can('import', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.import', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    CSVインポート
                </a>
            @endcan
            @can('create', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.create', $project) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    新規課題
                </a>
            @endcan
        </div>
    </div>

    {{-- Saved queries --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-500">保存済みクエリ:</span>
        @forelse ($this->savedQueries as $savedQuery)
            <button wire:key="saved-query-{{ $savedQuery->id }}" wire:click="loadQuery({{ $savedQuery->id }})" class="rounded-full border border-gray-300 px-3 py-1 text-gray-700 hover:bg-gray-50">
                {{ $savedQuery->name }}
            </button>
        @empty
            <span class="text-gray-400">なし</span>
        @endforelse
    </div>

    {{-- Filter builder --}}
    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                絞り込み適用
            </button>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                グループ化:
                <select wire:model.live="groupBy" class="rounded-md border-gray-300 text-sm">
                    <option value="">なし</option>
                    <option value="status_id">ステータス</option>
                    <option value="tracker_id">トラッカー</option>
                    <option value="priority_id">優先度</option>
                    <option value="assigned_to_id">担当者</option>
                    @foreach ($this->projectIssueCustomFields as $field)
                        @if (! $field->multiple)
                            <option value="cf_{{ $field->id }}" wire:key="group-by-cf-{{ $field->id }}">{{ $field->name }}</option>
                        @endif
                    @endforeach
                </select>
            </label>

            <div class="flex items-center gap-2 text-sm text-gray-700">
                表示列:
                @foreach ($this->availableColumns as $key => $label)
                    <label class="flex items-center gap-1" wire:key="column-option-{{ $key }}">
                        <input type="checkbox" wire:model="columns" value="{{ $key }}" class="rounded border-gray-300">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            <x-column-order :columns="$columns" :labels="$this->availableColumns" />

            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
                並べ替え(最大3列。列見出しのクリックは1列目のみ変更):
                @foreach ([[2, 'sortKey2', 'sortDirection2'], [3, 'sortKey3', 'sortDirection3']] as [$level, $keyProp, $dirProp])
                    <span class="flex items-center gap-1">
                        {{ $level }}列目:
                        <select wire:model.live="{{ $keyProp }}" class="rounded-md border-gray-300 text-sm">
                            <option value="">なし</option>
                            @foreach (self::DISPLAY_COLUMNS as $columnKey => $label)
                                <option value="{{ $columnKey }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="{{ $dirProp }}" class="rounded-md border-gray-300 text-sm">
                            <option value="asc">昇順</option>
                            <option value="desc">降順</option>
                        </select>
                    </span>
                @endforeach
            </div>

            <button wire:click="$toggle('showSaveForm')" class="text-sm text-indigo-600 hover:underline">クエリを保存</button>
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles" />
        @endif
    </div>

    @if ($this->canBulkEdit && count($selected) > 0)
        <form id="bulk-edit-form" wire:submit="applyBulkEdit" class="mb-4 space-y-3 rounded-md border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm font-medium text-gray-900">{{ count($selected) }}件を選択中 — 変更する項目だけ設定してください</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700">ステータス</label>
                    <select wire:model="bulkStatusId" class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        @if ($this->bulkStatusOptions->isEmpty()) disabled @endif>
                        <option value="">変更なし</option>
                        @foreach ($this->bulkStatusOptions as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">優先度</label>
                    <select wire:model="bulkPriorityId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->priorities as $priority)
                            <option value="{{ $priority->id }}">{{ $priority->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">担当者</label>
                    <select wire:model="bulkAssignedToId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->projectMembers as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">対象バージョン</label>
                    <select wire:model="bulkFixedVersionId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->projectVersions as $version)
                            <option value="{{ $version->id }}">{{ $version->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">トラッカー</label>
                    <select wire:model="bulkTrackerId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->bulkTrackers as $tracker)
                            <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                        @endforeach
                    </select>
                    @error('bulkTrackerId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">カテゴリ</label>
                    <select wire:model="bulkCategoryId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->bulkCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('bulkCategoryId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">開始日</label>
                    <input type="date" wire:model="bulkStartDate" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    @error('bulkStartDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">期日</label>
                    <input type="date" wire:model="bulkDueDate" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    @error('bulkDueDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">非公開</label>
                    <select wire:model="bulkIsPrivate" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        <option value="yes">非公開にする</option>
                        <option value="no">公開にする</option>
                    </select>
                </div>
                @can('manageSubtasks', [\App\Models\Issue::class, $project])
                    <div>
                        <label class="block text-xs font-medium text-gray-700">親課題(番号)</label>
                        <input type="number" wire:model="bulkParentId" placeholder="変更なし" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        @error('bulkParentId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endcan
                <div>
                    <label class="block text-xs font-medium text-gray-700">進捗率</label>
                    <select wire:model="bulkDoneRatio" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach (\App\Support\Issues\DoneRatioSteps::options() as $ratio)
                            <option value="{{ $ratio }}">{{ $ratio }}%</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700">コメント(任意)</label>
                <textarea wire:model="bulkComment" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm"></textarea>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    一括更新
                </button>
                <button type="button" wire:click="$set('selected', [])" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-white">
                    選択解除
                </button>
            </div>
        </form>
    @endif

    @if (count($selected) > 0 && auth()->user()?->can('move', $this->selectedIssues->first()) && $this->bulkMoveTargetProjects->isNotEmpty())
        <form wire:submit="applyBulkMove" class="mb-4 flex flex-wrap items-end gap-2 rounded-md border border-gray-200 bg-white p-4">
            <div>
                <label class="block text-xs font-medium text-gray-700">{{ count($selected) }}件を別のプロジェクトへ移動</label>
                <select wire:model.live="bulkMoveToProjectId" class="mt-1 block rounded-md border-gray-300 text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->bulkMoveTargetProjects as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                @error('bulkMoveToProjectId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            @if ($bulkMoveToProjectId)
                <div>
                    <label class="block text-xs font-medium text-gray-700">移動後のトラッカー</label>
                    <select wire:model="bulkMoveToTrackerId" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="">選択してください</option>
                        @foreach ($this->bulkMoveTargetTrackers as $candidateTracker)
                            <option value="{{ $candidateTracker->id }}">{{ $candidateTracker->name }}</option>
                        @endforeach
                    </select>
                    @error('bulkMoveToTrackerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="移動するとカテゴリ・対象バージョン・親課題はリセットされます。よろしいですか?"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    移動
                </button>
            @endif
        </form>
    @endif

    @if (count($selected) > 0 && $this->canBulkCopy && $this->bulkCopyTargetProjects->isNotEmpty())
        <form id="bulk-copy-form" wire:submit="applyBulkCopy" class="mb-4 flex flex-wrap items-end gap-2 rounded-md border border-gray-200 bg-white p-4">
            <div>
                <label class="block text-xs font-medium text-gray-700">{{ count($selected) }}件をコピーして複製</label>
                <select wire:model.live="bulkCopyToProjectId" class="mt-1 block rounded-md border-gray-300 text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->bulkCopyTargetProjects as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                @error('bulkCopyToProjectId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            @if ($bulkCopyToProjectId)
                <div>
                    <label class="block text-xs font-medium text-gray-700">複製先のトラッカー</label>
                    <select wire:model="bulkCopyToTrackerId" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="">選択してください</option>
                        @foreach ($this->bulkCopyTargetTrackers as $candidateTracker)
                            <option value="{{ $candidateTracker->id }}">{{ $candidateTracker->name }}</option>
                        @endforeach
                    </select>
                    @error('bulkCopyToTrackerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-1.5 text-xs text-gray-700">
                    <input type="checkbox" wire:model="bulkCopyAttachments" class="rounded border-gray-300">
                    添付ファイルも複製
                </label>
                <label class="flex items-center gap-1.5 text-xs text-gray-700">
                    <input type="checkbox" wire:model="bulkCopyWatchers" class="rounded border-gray-300">
                    ウォッチャーも複製
                </label>
                <label class="flex items-center gap-1.5 text-xs text-gray-700">
                    <input type="checkbox" wire:model="bulkCopySubtasks" class="rounded border-gray-300">
                    子課題も複製
                </label>
                <button type="submit"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    複製
                </button>
            @endif
        </form>
    @endif

    @if (count($selected) > 0 && auth()->user()?->can('delete', $this->selectedIssues->first()))
        <div class="mb-4">
            @if ($this->bulkDeleteHours > 0)
                <button type="button" wire:click="$set('confirmingBulkDelete', true)"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    選択した{{ count($selected) }}件を削除
                </button>

                @if ($confirmingBulkDelete)
                    <form wire:submit="applyBulkDelete" class="mt-3 space-y-3 rounded-md border border-red-200 bg-red-50 p-4">
                        <p class="text-sm font-medium text-red-800">
                            選択した課題には合計 {{ rtrim(rtrim(number_format($this->bulkDeleteHours, 2), '0'), '.') }} 時間の作業時間が記録されています。作業時間をどうしますか?
                        </p>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" wire:model.live="bulkTimeEntryTodo" value="nullify">
                            課題との紐付けを外してプロジェクトに残す
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" wire:model.live="bulkTimeEntryTodo" value="destroy">
                            作業時間も一緒に削除する
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" wire:model.live="bulkTimeEntryTodo" value="reassign">
                            このプロジェクトの別の課題へ付け替える: #
                            <input type="number" min="1" wire:model="bulkReassignToId" wire:focus="$set('bulkTimeEntryTodo', 'reassign')"
                                class="w-24 rounded-md border-gray-300 text-sm">
                        </label>
                        @error('bulkTimeEntryTodo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('bulkReassignToId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">
                                削除する
                            </button>
                            <button type="button" wire:click="$set('confirmingBulkDelete', false)"
                                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                キャンセル
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <button type="button" wire:click="applyBulkDelete"
                    wire:confirm="選択した{{ count($selected) }}件の課題を削除します。この操作は取り消せません。よろしいですか?"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    選択した{{ count($selected) }}件を削除
                </button>
            @endif
        </div>
    @endif

    @if (ListDefaults::issueTotals() !== [])
        <p class="mb-2 text-xs text-gray-500" data-list-totals>
            合計:
            @foreach (ListDefaults::issueTotals() as $totalKey)
                @php
                    $totalValue = ['estimated_hours' => 'estimated', 'spent_hours' => 'spent', 'estimated_remaining_hours' => 'remaining'][$totalKey];
                @endphp
                {{ $loop->first ? '' : '/ ' }}{{ ListDefaults::ISSUE_TOTALS[$totalKey] }} {{ \App\Support\Format\Hours::format($this->listTotals[$totalValue]) }} 時間
            @endforeach
        </p>
    @endif

    @foreach ($this->groupedIssues as $groupLabel => $groupIssues)
        @php
            $groupKey = $groupLabel !== '' ? $groupLabel : '__ungrouped__';
            $groupTotal = $this->groupTotals[$groupLabel] ?? null;
        @endphp
        @if ($groupBy !== null)
            <h2 wire:key="group-heading-{{ $groupKey }}" class="mb-2 mt-4 text-sm font-semibold text-gray-900">
                {{ $groupLabel ?: '(未設定)' }} ({{ $groupTotal['count'] ?? $groupIssues->count() }})
                @if ($groupTotal !== null)
                    <span class="ml-2 text-xs font-normal text-gray-500">
                        予定 {{ \App\Support\Format\Hours::format($groupTotal['estimated']) }} 時間
                        / 実績 {{ \App\Support\Format\Hours::format($groupTotal['spent']) }} 時間
                    </span>
                @endif
            </h2>
        @endif

        <div wire:key="group-table-{{ $groupKey }}" class="overflow-x-auto rounded-md border border-gray-200 bg-white mb-4">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        @if ($this->canBulkEdit)
                            <th class="px-4 py-2"></th>
                        @endif
                        <th class="px-4 py-2">#</th>
                        @foreach (array_diff($columns, self::BLOCK_COLUMNS) as $columnKey)
                            <th wire:key="column-heading-{{ $columnKey }}" class="px-4 py-2">
                                @if (in_array($columnKey, self::COMPUTED_HOUR_COLUMNS, true))
                                    {{ $this->availableColumns[$columnKey] ?? $columnKey }}
                                @else
                                    <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-gray-900">
                                        {{ $this->availableColumns[$columnKey] ?? $columnKey }}
                                        @if ($sortKey === $columnKey)
                                            <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groupIssues as $issue)
                        <tr wire:key="issue-row-{{ $issue->id }}" @if ($this->canBulkEdit) x-on:contextmenu.prevent="showMenu($event, {{ $issue->id }})" @endif class="{{ in_array((string) $issue->id, array_map('strval', $selected), true) ? 'bg-indigo-50' : '' }}">
                            @if ($this->canBulkEdit)
                                <td class="px-4 py-2">
                                    <input type="checkbox" wire:model="selected" value="{{ $issue->id }}" class="rounded border-gray-300">
                                </td>
                            @endif
                            <td class="px-4 py-2 text-gray-500">{{ $issue->id }}</td>
                            @foreach (array_diff($columns, self::BLOCK_COLUMNS) as $columnKey)
                                <td wire:key="issue-{{ $issue->id }}-column-{{ $columnKey }}" class="px-4 py-2">
                                    @if ($columnKey === 'subject')
                                        <a href="{{ route('issues.show', [$project, $issue]) }}" class="text-indigo-600 hover:underline">
                                            {{ $issue->subject }}
                                        </a>
                                    @elseif (str_starts_with($columnKey, 'cf_'))
                                        @foreach ($this->customFieldCellValues($issue, $columnKey) as $cell)
                                            <x-custom-field-value :field="$cell['field']" :value="$cell['value']" />@if (! $loop->last), @endif
                                        @endforeach
                                    @else
                                        {{ $this->columnValue($issue, $columnKey) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        @foreach (array_intersect(self::BLOCK_COLUMNS, $columns) as $blockKey)
                            @if (filled($this->columnValue($issue, $blockKey)))
                                <tr wire:key="issue-{{ $issue->id }}-block-{{ $blockKey }}" class="bg-gray-50" data-block-column="{{ $blockKey }}">
                                    <td colspan="{{ count($columns) + 2 }}" class="px-4 py-2 text-xs text-gray-600">
                                        <span class="font-medium text-gray-500">{{ $this->availableColumns[$blockKey] }}:</span>
                                        <span class="whitespace-pre-line">{{ \Illuminate\Support\Str::limit($this->columnValue($issue, $blockKey), 600) }}</span>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 2 }}" class="px-4 py-6 text-center text-gray-500">課題がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->issues->perPage()" :total="$this->issues->total()" /></div>
    {{ $this->issues->links() }}
</div>
