<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\SelectsPageSize;
use App\Concerns\ReordersColumns;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\ListDefaults;
use App\Support\Query\ListQueryString;
use App\Support\Query\TimeEntryColumns;
use App\Support\Query\QueryFilterEngine;
use App\Support\Issues\ContextMenuCustomFields;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
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
    use SelectsPageSize;
    use WithPagination;
    use ReordersColumns;

    /**
     * Columns selectable for display/CSV export — mirrors issues.index's
     * own DISPLAY_COLUMNS (Redmine's TimeEntryQuery columns). Sorting by
     * a column that isn't a registered filter field (project_id here,
     * tweek) is a harmless no-op rather than a real sort, same as any
     * unregistered column on the issues list.
     *
     * @var array<string, string>
     */
    public const DISPLAY_COLUMNS = [
        'project_id' => 'プロジェクト',
        'spent_on' => '日付',
        'created_at' => '作成日',
        'tweek' => '週',
        'author_id' => '作成者',
        'user_id' => 'ユーザー',
        'activity_id' => '作業分類',
        'issue_id' => '課題',
        'comments' => 'コメント',
        'hours' => '時間',
    ];

    /**
     * Translated header labels for DISPLAY_COLUMNS (constants can't call __()).
     *
     * @return array<string, string>
     */
    public function displayColumnLabels(): array
    {
        return [
            'project_id' => __('プロジェクト'),
            'spent_on' => __('日付'),
            'created_at' => __('作成日'),
            'tweek' => __('週'),
            'author_id' => __('作成者'),
            'user_id' => __('ユーザー'),
            'activity_id' => __('作業分類'),
            'issue_id' => __('課題'),
            'comments' => __('コメント'),
            'hours' => __('時間'),
        ];
    }

    public Project $project;

    #[Url]
    public ?string $sortKey = 'spent_on';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public ?string $groupBy = null;

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['spent_on', 'user_id', 'activity_id', 'issue_id', 'comments', 'hours'];

    public string $csvEncoding = 'UTF-8';

    public string $csvSeparator = ',';

    /** @var array<int, int> */
    public array $selected = [];

    public ?int $bulkProjectId = null;

    /** An issue id, `none` to detach the entries from their issue, or '' to leave it. */
    public string $bulkIssueId = '';

    public ?int $bulkUserId = null;

    public string $bulkHours = '';

    public ?int $bulkActivityId = null;

    public string $bulkSpentOn = '';

    public string $bulkComments = '';

    /** @var array<int, mixed> custom field id => the value to set on every selected entry (blank = leave alone) */
    public array $bulkCustomFieldValues = [];

    /**
     * Ids of custom fields the bulk edit clears (Redmine's `__none__`).
     *
     * @var list<int>
     */
    public array $bulkClearCustomFields = [];

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * Set by editQuery() while the save form is prefilled with an
     * existing query's settings, for saveQuery() to update in place
     * instead of creating a new one (A15-07b, mirroring the issue
     * list's own editQuery()).
     */
    public ?int $editingQueryId = null;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [TimeEntry::class, $project]);

        $this->project = $project;

        // Redmine's time_entry_list_defaults: the columns a list starts with
        // (a `columns` URL parameter still wins).
        if (! request()->has('columns')) {
            $this->columns = ListDefaults::timeEntryColumns();
        }
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProject($this->project, scopeProjects: $this->scopeProjects));
    }

    /**
     * The project plus, with display_subprojects_issues on, the subprojects
     * whose entries the list also shows (Redmine's TimeEntryQuery goes
     * through project_statement). Entries of a subproject are listed and
     * linked, but the selection, context menu and inline delete here act
     * only on this project's own entries.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function scopeProjects(): Collection
    {
        return SubprojectScope::projectsForTimeEntries($this->project, auth()->user(), $this->builtFilters());
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filteredTimeEntriesQuery(): Builder
    {
        $query = TimeEntry::query()
            ->visibleToAcrossProjects(auth()->user(), $this->scopeProjects)
            ->with(['project', 'user', 'author', 'activity', 'issue.project', 'customFieldValues', ...TimeEntryColumns::relations([...$this->columns, ...($this->groupBy !== null ? [$this->groupBy] : [])])]);

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        // The issue and custom field columns sort through TimeEntryColumns,
        // which sees only what the viewer may see; the rest through the engine.
        if ($this->sortKey !== null) {
            $sortedByExtraColumn = TimeEntryColumns::handles($this->sortKey)
                && array_key_exists($this->sortKey, $this->availableColumns)
                && $this->extraColumns->applySort($query, $this->sortKey, $this->sortDirection);

            if (! $sortedByExtraColumn) {
                $query = $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
            }
        } else {
            $query->orderByDesc('spent_on');
        }

        return $query;
    }

    /**
     * One page of the filtered entries; the totals and the group subtotals
     * below still cover every matching entry.
     *
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    #[Computed]
    public function timeEntries(): LengthAwarePaginator
    {
        return $this->filteredTimeEntriesQuery()->paginate($this->pageSize());
    }

    /**
     * @return Collection<string, EloquentCollection<int, TimeEntry>>
     */
    #[Computed]
    public function groupedTimeEntries(): Collection
    {
        $entries = $this->timeEntries->getCollection();

        if ($this->groupBy === null) {
            return collect(['' => $entries]);
        }

        return $entries->groupBy(fn (TimeEntry $entry) => $this->columnValue($entry, $this->groupBy));
    }

    /**
     * Entry count and hours of every group across all pages (Redmine's group
     * totals), keyed like groupedTimeEntries. Only computed while grouping,
     * which reads every matching entry.
     *
     * @return array<string, array{count: int, hours: string}>
     */
    #[Computed]
    public function groupSubtotals(): array
    {
        if ($this->groupBy === null) {
            return [];
        }

        return $this->filteredTimeEntriesQuery()->get()
            ->groupBy(fn (TimeEntry $entry) => $this->columnValue($entry, $this->groupBy))
            ->map(fn (EloquentCollection $entries) => [
                'count' => $entries->count(),
                'hours' => \App\Support\Format\Hours::format((float) $entries->sum('hours')),
            ])
            ->all();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
        unset($this->timeEntries, $this->groupedTimeEntries, $this->groupSubtotals, $this->scopeProjects, $this->engine);
    }

    public function sortBy(string $key): void
    {
        if ($this->sortKey === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDirection = 'asc';
        }
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

    /**
     * Whether the viewer may save queries (Redmine's save_queries).
     */
    #[Computed]
    public function canSaveQueries(): bool
    {
        return app(\App\Support\Authorization\AuthorizationService::class)->can(auth()->user(), 'save_queries', $this->project);
    }

    public function saveQuery(): void
    {
        abort_unless($this->canSaveQueries, 403);

        $editing = $this->editingQueryId !== null ? SavedQuery::findOrFail($this->editingQueryId) : null;

        if ($editing !== null) {
            $this->authorize('update', $editing);
        }

        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $this->project);

        $attributes = [
            'name' => $data['newQueryName'],
            'project_id' => $this->project->id,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => $this->columns,
            'sort_criteria' => $this->sortKey ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => $this->groupBy,
        ];

        if ($editing !== null) {
            $editing->update($attributes);
            $query = $editing;
        } else {
            $query = SavedQuery::create([...$attributes, 'type' => QueryType::TimeEntry->value, 'user_id' => auth()->id()]);
        }

        $query->roles()->sync($visibility === QueryVisibility::Roles->value ? $data['newQueryRoleIds'] : []);

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', $editing !== null ? __('クエリを更新しました。') : __('クエリを保存しました。'));
    }

    /**
     * Opens the save form prefilled with an existing saved query's
     * settings, for saveQuery() to update in place — Redmine's
     * QueriesController#edit, mirrored from the issue list's own
     * editQuery() (A15-07b).
     */
    public function editQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::findOrFail($queryId);
        $this->authorize('update', $query);

        $this->loadQuery($queryId);
        $this->editingQueryId = $query->id;
        $this->newQueryName = $query->name;
        $this->newQueryVisibility = $query->visibility->value;
        $this->newQueryRoleIds = $query->roles->pluck('id')->all();
        $this->showSaveForm = true;
    }

    public function cancelEditQuery(): void
    {
        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
    }

    public function deleteQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::findOrFail($queryId);
        $this->authorize('delete', $query);

        $query->delete();

        if ($this->editingQueryId === $queryId) {
            $this->cancelEditQuery();
        }

        unset($this->savedQueries);
        session()->flash('status', __('クエリを削除しました。'));
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

        $this->columns = $query->column_names !== [] ? $query->column_names : ListDefaults::DEFAULT_TIME_ENTRY_COLUMNS;
        $this->groupBy = $query->group_by;

        if ($query->sort_criteria !== [] && $query->sort_criteria !== null) {
            [$this->sortKey, $this->sortDirection] = $query->sort_criteria[0];
        }

        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleIn($this->project, QueryType::TimeEntry, auth()->user());
    }

    /**
     * The selectable columns: the fixed ones plus the issue's attributes and
     * the custom field columns the viewer may see in a project the list
     * covers (TimeEntryColumns).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableColumns(): array
    {
        return [...$this->displayColumnLabels(), ...$this->extraColumns->labels()];
    }

    #[Computed]
    public function extraColumns(): TimeEntryColumns
    {
        return new TimeEntryColumns(auth()->user(), $this->scopeProjects);
    }

    /** @var array<int, bool> issue id => whether the viewer may see it */
    private array $issueVisibility = [];

    /**
     * Whether the viewer may see the entry's issue: an entry logged on an
     * issue the viewer cannot see shows only its number, as in Redmine
     * (format_object links an issue only when it is visible).
     */
    public function issueIsVisible(TimeEntry $entry): bool
    {
        if ($entry->issue === null) {
            return false;
        }

        return $this->issueVisibility[$entry->issue->id] ??= \Illuminate\Support\Facades\Gate::allows('view', $entry->issue);
    }

    public function columnValue(TimeEntry $entry, string $key): string
    {
        if (TimeEntryColumns::handles($key)) {
            // Only a column the viewer is offered: a URL or saved query
            // naming a role-restricted field shows nothing.
            return array_key_exists($key, $this->availableColumns) ? $this->extraColumns->value($entry, $key) : '';
        }

        return match ($key) {
            'project_id' => $entry->project->name,
            'user_id' => $entry->user->displayName(),
            'activity_id' => $entry->activity->name,
            'spent_on' => \App\Support\Format\DateTimes::date($entry->spent_on),
            'created_at' => \App\Support\Format\DateTimes::dateTime($entry->created_at) ?? '',
            'tweek' => (string) $entry->spent_on->isoWeek(),
            'author_id' => $entry->author?->displayName() ?? '',
            'hours' => \App\Support\Format\Hours::format($entry->hours, false),
            'issue_id' => $entry->issue ? ($this->issueIsVisible($entry) ? "#{$entry->issue->id} {$entry->issue->subject}" : "#{$entry->issue->id}") : '',
            'comments' => (string) $entry->comments,
            default => '',
        };
    }

    #[Computed]
    public function totalHours(): string
    {
        return \App\Support\Format\Hours::format((float) $this->filteredTimeEntriesQuery()->reorder()->sum('time_entries.hours'));
    }

    #[Computed]
    public function canManage(): bool
    {
        $authorization = app(AuthorizationService::class);

        return $authorization->can(auth()->user(), 'edit_time_entries', $this->project)
            || $authorization->can(auth()->user(), 'edit_own_time_entries', $this->project);
    }

    /**
     * Whether bulk edit may reassign entries to another user — Redmine's
     * log_time_for_other_users.
     */
    #[Computed]
    public function canLogForOthers(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'log_time_for_other_users', $this->project);
    }

    public function deleteEntry(int $timeEntryId): void
    {
        $entry = TimeEntry::query()->where('project_id', $this->project->id)->findOrFail($timeEntryId);

        $this->authorize('delete', $entry);

        app(TimeEntryService::class)->delete($entry);

        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    /**
     * This project's effective TimeEntryActivity set — mirrors
     * time-entries/form.blade.php's own `activities()` computed.
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->project->activities(includeInactive: true);
    }

    /**
     * Projects the selected entries may be moved to: the ones the user may
     * log time in, this project included.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function moveTargets(): Collection
    {
        return Project::query()->orderBy('name')->get()
            ->filter(fn (Project $candidate) => $candidate->is($this->project) || auth()->user()->can('create', [TimeEntry::class, $candidate]))
            ->values();
    }

    #[Computed]
    public function bulkTargetProject(): Project
    {
        if ($this->bulkProjectId === null) {
            return $this->project;
        }

        return $this->moveTargets->firstWhere('id', $this->bulkProjectId) ?? $this->project;
    }

    /**
     * Anything the old target's issue/user/activity picks no longer fit.
     */
    public function updatedBulkProjectId(): void
    {
        $this->bulkIssueId = '';
        $this->bulkUserId = null;
        $this->bulkActivityId = null;
    }

    /**
     * @return EloquentCollection<int, TimeEntry>
     */
    #[Computed]
    public function selectedTimeEntries(): EloquentCollection
    {
        if ($this->selected === []) {
            return new EloquentCollection;
        }

        return TimeEntry::query()
            ->whereIn('id', $this->selected)
            ->where('project_id', $this->project->id)
            ->get();
    }

    /**
     * Right-click on a row: an unselected entry becomes the only selection,
     * a selected one keeps the whole selection. Only entries the user may
     * edit can be picked, like the row checkboxes.
     */
    public function openContextMenu(int $timeEntryId): void
    {
        $entry = TimeEntry::query()->where('project_id', $this->project->id)->find($timeEntryId);

        abort_if($entry === null, 404);
        $this->authorize('update', $entry);

        if (! in_array($entry->id, array_map('intval', $this->selected), true)) {
            $this->selected = [(string) $entry->id];
        }

        unset($this->selectedTimeEntries);
    }

    /**
     * The one quick change the context menu offers (the activity), applied
     * through the bulk edit's validation and authorization.
     */
    public function contextUpdateActivity(int $activityId): void
    {
        $this->reset(['bulkProjectId', 'bulkIssueId', 'bulkUserId', 'bulkHours', 'bulkSpentOn', 'bulkComments', 'bulkCustomFieldValues', 'bulkClearCustomFields']);
        $this->bulkActivityId = $activityId;

        $this->applyBulkEdit();
    }

    /**
     * The context menu's custom field submenus (Redmine's
     * context_menus/time_entries `@options_by_custom_field`).
     *
     * @return Collection<int, array{field: \App\Models\CustomField, options: array<string, string>}>
     */
    #[Computed]
    public function contextMenuCustomFields(): Collection
    {
        return ContextMenuCustomFields::optionsFor($this->bulkCustomFields, collect([$this->bulkTargetProject]));
    }

    /**
     * A custom field value from the context menu, applied through the bulk
     * edit; `__none__` clears a field that is not required.
     */
    public function contextUpdateCustomField(int $fieldId, string $value): void
    {
        $entry = $this->contextMenuCustomFields->first(fn (array $entry) => $entry['field']->id === $fieldId);

        abort_unless($this->canManage && $entry !== null, 403);
        abort_unless($value === ContextMenuCustomFields::NONE ? ! $entry['field']->is_required : array_key_exists($value, $entry['options']), 422);

        $this->reset(['bulkProjectId', 'bulkIssueId', 'bulkUserId', 'bulkHours', 'bulkActivityId', 'bulkSpentOn', 'bulkComments', 'bulkCustomFieldValues', 'bulkClearCustomFields']);

        if ($value === ContextMenuCustomFields::NONE) {
            $this->bulkClearCustomFields = [$fieldId];
        } else {
            $this->bulkCustomFieldValues = [$fieldId => $value];
        }

        $this->applyBulkEdit();
    }

    /**
     * The custom fields the bulk edit can set: single-value ones the viewer may
     * see and edit in the project the entries land in.
     *
     * @return Collection<int, \App\Models\CustomField>
     */
    #[Computed]
    public function bulkCustomFields(): Collection
    {
        return (new TimeEntry)->forceFill(['project_id' => $this->bulkTargetProject->id])->relevantCustomFields()
            ->filter(fn (\App\Models\CustomField $field) => ! $field->multiple && ! $field->format() instanceof \App\CustomFields\Formats\AttachmentFormat && $field->editableBy(auth()->user()))
            ->values();
    }

    public function applyBulkEdit(): void
    {
        $entries = $this->selectedTimeEntries;

        abort_if($entries->isEmpty(), 404);

        foreach ($entries as $entry) {
            $this->authorize('update', $entry);
        }

        $target = $this->bulkTargetProject;
        $moving = $target->id !== $this->project->id;

        // The project field is a dropdown of allowed targets; anything else
        // was tampered with.
        abort_if($this->bulkProjectId !== null && $this->bulkProjectId !== $target->id, 403);
        abort_if($this->bulkUserId !== null && ! $this->canLogForOthers, 403);

        if ($moving) {
            $this->authorize('create', [TimeEntry::class, $target]);
        }

        $this->bulkHours = \App\Support\Format\Hours::normalizeInput($this->bulkHours);

        $data = $this->validate([
            'bulkActivityId' => ['nullable', Rule::in($target->activities(includeInactive: true)->pluck('id')->all())],
            'bulkSpentOn' => [$this->bulkSpentOn === '' ? 'nullable' : 'date'],
            'bulkComments' => ['nullable', 'string'],
            'bulkHours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'bulkUserId' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $target->id)],
            'bulkIssueId' => ['nullable', fn (string $attribute, mixed $value, \Closure $fail) => $value === 'none'
                || ! filled($value)
                || (ctype_digit((string) $value) && $target->issues()->whereKey((int) $value)->exists())
                    ? null
                    : $fail(__('選択した課題はこのプロジェクトにありません。'))],
        ]);

        // Only the fields given a value are validated and set.
        $customFieldInput = collect($this->bulkCustomFieldValues)->filter(fn ($value) => filled($value))->only($this->bulkCustomFields->pluck('id')->all())->all();
        // A user or version field is checked against the target project's values.
        $customFieldRules = collect(\App\Models\CustomField::formValidationRules($this->bulkCustomFields->whereIn('id', array_keys($customFieldInput)), project: $this->bulkTargetProject))
            ->mapWithKeys(fn ($rules, $key) => [str_replace('customFieldValues.', 'bulkCustomFieldValues.', $key) => $rules])->all();
        if ($customFieldRules !== []) {
            $this->validate($customFieldRules);
        }

        foreach ($this->bulkCustomFields->whereIn('id', $this->bulkClearCustomFields) as $field) {
            abort_if($field->is_required, 422);
            $customFieldInput[$field->id] = '';
        }

        $issueChoice = $data['bulkIssueId'] ?? '';

        if ($issueChoice !== '' && $issueChoice !== 'none') {
            $this->authorize('view', $target->issues()->findOrFail((int) $issueChoice));
        }

        // Redmine rejects an entry whose issue belongs to another project, so
        // a move has to say what happens to the issue.
        if ($moving && $issueChoice === '' && $entries->contains(fn (TimeEntry $entry) => $entry->issue_id !== null)) {
            $this->addError('bulkIssueId', __('別のプロジェクトへ移動するときは、課題を指定するか「課題を外す」を選んでください。'));

            return;
        }

        $changes = array_filter([
            'project_id' => $moving ? $target->id : null,
            'activity_id' => $data['bulkActivityId'],
            'user_id' => $data['bulkUserId'] ?? null,
            'hours' => filled($data['bulkHours'] ?? null) ? $data['bulkHours'] : null,
            'spent_on' => $data['bulkSpentOn'] !== '' ? $data['bulkSpentOn'] : null,
            'comments' => $data['bulkComments'] !== '' ? $data['bulkComments'] : null,
        ], fn ($value) => $value !== null);

        if ($issueChoice === 'none') {
            $changes['issue_id'] = null;
        } elseif ($issueChoice !== '') {
            $changes['issue_id'] = (int) $issueChoice;
        }

        $timeEntryService = app(TimeEntryService::class);

        try {
            foreach ($entries as $entry) {
                $timeEntryService->update($entry, $changes);

                if ($customFieldInput !== []) {
                    $entry->setCustomFieldValues($customFieldInput);
                }
            }
        } catch (ValidationException $exception) {
            // A `timelog_*` setting rejected one of the entries; earlier ones
            // in the selection are already saved, as in Redmine's bulk_update.
            $this->addError('bulkComments', collect($exception->errors())->flatten()->first());

            return;
        }

        $count = $entries->count();

        $this->reset(['selected', 'bulkProjectId', 'bulkIssueId', 'bulkUserId', 'bulkHours', 'bulkActivityId', 'bulkSpentOn', 'bulkComments', 'bulkCustomFieldValues', 'bulkClearCustomFields']);
        unset($this->timeEntries, $this->groupedTimeEntries, $this->selectedTimeEntries);

        session()->flash('status', __(':count件の工数記録を更新しました。', ['count' => $count]));
    }

    public function applyBulkDelete(): void
    {
        $entries = $this->selectedTimeEntries;

        abort_if($entries->isEmpty(), 404);

        foreach ($entries as $entry) {
            $this->authorize('delete', $entry);
        }

        $count = $entries->count();
        $timeEntryService = app(TimeEntryService::class);

        foreach ($entries as $entry) {
            $timeEntryService->delete($entry);
        }

        $this->reset('selected');
        unset($this->timeEntries, $this->groupedTimeEntries, $this->selectedTimeEntries);

        session()->flash('status', __(':count件の工数記録を削除しました。', ['count' => $count]));
    }

    /**
     * The Atom feed link carries the list's current filters, so the feed
     * shows the same entries (matching how the issue list's own Atom link
     * behaves).
     */
    #[Computed]
    public function atomUrl(): string
    {
        return route('time-entries.atom', $this->project).'?'.http_build_query([
            'key' => auth()->user()?->atomKey(),
            ...ListQueryString::toQueryParameters($this->activeFilterKeys, $this->filterOperators, $this->filterValues),
        ]);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', [TimeEntry::class, $this->project]);

        $query = $this->filteredTimeEntriesQuery();
        $columns = $this->columns;
        // Re-validated against the allowlist here rather than trusted from
        // the live property, since these drive raw file-writing behavior.
        $encoding = in_array($this->csvEncoding, ['UTF-8', 'SJIS-win'], true) ? $this->csvEncoding : 'UTF-8';
        $separator = in_array($this->csvSeparator, [',', ';', "\t"], true) ? $this->csvSeparator : ',';

        return response()->streamDownload(function () use ($query, $columns, $encoding, $separator) {
            $handle = fopen('php://output', 'w');

            // A UTF-8 BOM lets Excel auto-detect the encoding instead of
            // mis-rendering non-ASCII text as mojibake — matches Redmine's
            // Redmine::Export::CSV, which does the same for UTF-8 exports.
            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $writeRow = function (array $row) use ($handle, $separator, $encoding): void {
                $row = \App\Support\Export\CsvCell::row($row);

                if ($encoding !== 'UTF-8') {
                    $row = array_map(fn (string $value) => mb_convert_encoding($value, $encoding, 'UTF-8'), $row);
                }

                fputcsv($handle, $row, $separator);
            };

            $writeRow(array_map(fn (string $key) => $this->availableColumns[$key] ?? $key, $columns));

            $query->chunk(200, function ($chunk) use ($writeRow, $columns) {
                foreach ($chunk as $entry) {
                    $writeRow(array_map(fn (string $key) => $this->columnValue($entry, $key), $columns));
                }
            });

            fclose($handle);
        }, "{$this->project->identifier}-time_entries.csv");
    }
}; ?>

<div x-data="{ menu: { open: false, x: 0, y: 0 }, showMenu(event, entryId) { const x = event.clientX, y = event.clientY; $wire.openContextMenu(entryId).then(() => { this.menu = { open: true, x: Math.min(x, window.innerWidth - 220), y: Math.min(y, window.innerHeight - 200) }; }); } }"
    x-on:click.window="menu.open = false" x-on:keydown.escape.window="menu.open = false">
    @if ($this->canManage && count($selected) > 0)
        @php $menuEntries = $this->selectedTimeEntries; @endphp
        <div x-show="menu.open" x-cloak x-on:click.stop x-bind:style="`left:${menu.x}px;top:${menu.y}px`" data-context-menu
            class="fixed z-50 w-52 rounded-md border border-neutral-200 bg-surface py-1 text-sm shadow-lg">
            @if ($menuEntries->count() === 1)
                <a href="{{ route('time-entries.edit', [$project, $menuEntries->first()]) }}" class="block px-3 py-1.5 text-neutral-700 hover:bg-neutral-100">{{ __('編集') }}</a>
            @else
                <a href="#bulk-edit-form" x-on:click="menu.open = false" class="block px-3 py-1.5 text-neutral-700 hover:bg-neutral-100">{{ __('一括編集') }}</a>
            @endif
            @if ($this->project->activities(includeInactive: false)->isNotEmpty())
                <div class="group relative">
                    <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-neutral-700 group-hover:bg-neutral-100">{{ __('作業分類') }} <span class="text-neutral-400">›</span></span>
                    <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-neutral-200 bg-surface py-1 shadow-lg group-hover:block">
                        @foreach ($this->project->activities(includeInactive: false) as $activity)
                            <button type="button" wire:key="context-activity-{{ $activity->id }}" wire:click="contextUpdateActivity({{ $activity->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ $activity->name }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
            @foreach ($this->contextMenuCustomFields as $menuEntry)
                <div class="group relative" wire:key="context-menu-cf-{{ $menuEntry['field']->id }}" data-context-menu-custom-field="{{ $menuEntry['field']->id }}">
                    <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-neutral-700 group-hover:bg-neutral-100">{{ $menuEntry['field']->name }} <span class="text-neutral-400">›</span></span>
                    <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-neutral-200 bg-surface py-1 shadow-lg group-hover:block">
                        @foreach ($menuEntry['options'] as $menuValue => $menuText)
                            <button type="button" wire:click="contextUpdateCustomField({{ $menuEntry['field']->id }}, @js((string) $menuValue))" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ $menuText }}</button>
                        @endforeach
                        @unless ($menuEntry['field']->is_required)
                            <button type="button" wire:click="contextUpdateCustomField({{ $menuEntry['field']->id }}, '{{ ContextMenuCustomFields::NONE }}')" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-500 hover:bg-neutral-100">{{ __('(なし)') }}</button>
                        @endunless
                    </div>
                </div>
            @endforeach
            @if ($menuEntries->every(fn ($entry) => auth()->user()?->can('delete', $entry)))
                <button type="button" wire:click="applyBulkDelete" wire:confirm="{{ __('選択した:count件の工数記録を削除します。この操作は取り消せません。よろしいですか?', ['count' => count($selected)]) }}" x-on:click="menu.open = false" class="block w-full border-t border-neutral-100 px-3 py-1.5 text-left text-danger-bolder hover:bg-danger-subtlest">{{ __('削除') }}</button>
            @endif
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — {{ __('工数') }}</h1>
            @if (ListDefaults::timeEntriesShowHoursTotal())
                <p class="mt-1 text-sm text-neutral-500">{{ __('合計: :hours 時間', ['hours' => $this->totalHours]) }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <select wire:model="csvEncoding" title="{{ __('文字コード') }}" class="rounded-md border-neutral-300 text-xs">
                <option value="UTF-8">UTF-8</option>
                <option value="SJIS-win">Shift_JIS</option>
            </select>
            <select wire:model="csvSeparator" title="{{ __('区切り文字') }}" class="rounded-md border-neutral-300 text-xs">
                <option value=",">{{ __('カンマ') }}</option>
                <option value=";">{{ __('セミコロン') }}</option>
                <option value="{{ "\t" }}">{{ __('タブ') }}</option>
            </select>
            <button wire:click="exportCsv" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('CSVエクスポート') }}
            </button>
            <a href="{{ route('time-entries.report', $project) }}" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('レポート') }}
            </a>
            <a href="{{ $this->atomUrl }}" class="text-xs text-warning hover:underline">Atom</a>
            @can('import', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.import', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ __('CSVインポート') }}
                </a>
            @endcan
            @can('create', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.create', $project) }}"
                    class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    {{ __('工数を記録') }}
                </a>
            @endcan
        </div>
    </div>

    {{-- Saved queries --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-neutral-500">{{ __('保存済みクエリ:') }}</span>
        @forelse ($this->savedQueries as $savedQuery)
            <x-saved-query-pill :query="$savedQuery" />
        @empty
            <span class="text-neutral-400">{{ __('なし') }}</span>
        @endforelse
    </div>

    {{-- Filter builder --}}
    <div class="mb-4 rounded-md border border-neutral-200 bg-surface p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('絞り込み適用') }}
            </button>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                {{ __('グループ化:') }}
                <select wire:model.live="groupBy" class="rounded-md border-neutral-300 text-sm">
                    <option value="">{{ __('なし') }}</option>
                    <option value="user_id">{{ __('ユーザー') }}</option>
                    <option value="activity_id">{{ __('作業分類') }}</option>
                    <option value="spent_on">{{ __('日付') }}</option>
                    @foreach ($this->extraColumns->groupableLabels() as $groupKey => $groupLabel)
                        <option value="{{ $groupKey }}" wire:key="group-by-{{ $groupKey }}">{{ $groupLabel }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-center gap-2 text-sm text-neutral-700">
                {{ __('表示列:') }}
                @foreach ($this->availableColumns as $key => $label)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="columns" value="{{ $key }}" class="rounded border-neutral-300">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            <x-column-order :columns="$columns" :labels="$this->availableColumns" />

            @if ($this->canSaveQueries)
                <button wire:click="$toggle('showSaveForm')" class="text-sm text-brand-bold hover:underline">{{ __('クエリを保存') }}</button>
            @endif
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles"
                :editing="$editingQueryId !== null" />
        @endif
    </div>

    @if ($this->canManage && count($selected) > 0)
        <form id="bulk-edit-form" wire:submit="applyBulkEdit" class="mb-4 space-y-3 rounded-md border border-brand-subtle bg-brand-subtlest p-4">
            <p class="text-sm font-medium text-neutral-900">{{ __(':count件を選択中 — 変更する項目だけ設定してください', ['count' => count($selected)]) }}</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @if ($this->moveTargets->count() > 1)
                    <div>
                        <label class="block text-xs font-medium text-neutral-700">{{ __('プロジェクト') }}</label>
                        <select wire:model.live="bulkProjectId" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                            <option value="">{{ __('変更なし') }}</option>
                            @foreach ($this->moveTargets->reject(fn ($candidate) => $candidate->is($this->project)) as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('課題(番号)') }}</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="text" wire:model="bulkIssueId" placeholder="{{ __('変更なし') }}" inputmode="numeric"
                            class="block w-full rounded-md border-neutral-300 text-sm">
                        <button type="button" wire:click="$set('bulkIssueId', 'none')" class="shrink-0 text-xs text-brand-bold hover:underline">{{ __('課題を外す') }}</button>
                    </div>
                    @error('bulkIssueId') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                @if ($this->canLogForOthers)
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('ユーザー') }}</label>
                    <select wire:model="bulkUserId" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                        <option value="">{{ __('変更なし') }}</option>
                        @foreach (\App\Models\User::sortByFormat($this->bulkTargetProject->loadMissing('users')->users) as $member)
                            <option value="{{ $member->id }}">{{ $member->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('bulkUserId') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('時間') }}</label>
                    <input type="text" inputmode="decimal" wire:model="bulkHours" placeholder="{{ __('変更なし') }}"
                        class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                    @error('bulkHours') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('作業分類') }}</label>
                    <select wire:model="bulkActivityId" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                        <option value="">{{ __('変更なし') }}</option>
                        @foreach ($this->bulkTargetProject->activities(includeInactive: true) as $activity)
                            <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('日付') }}</label>
                    <input type="date" wire:model="bulkSpentOn" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                </div>
            </div>

            @if ($this->bulkCustomFields->isNotEmpty())
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-bulk-custom-fields>
                    @foreach ($this->bulkCustomFields as $field)
                        <div wire:key="bulk-cf-{{ $field->id }}">
                            <label class="block text-xs font-medium text-neutral-700">{{ $field->name }}</label>
                            @if ($field->format() instanceof \App\CustomFields\Formats\ProjectScopedFormat)
                                <select wire:model="bulkCustomFieldValues.{{ $field->id }}" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                                    <option value="">{{ __('変更なし') }}</option>
                                    @foreach ($field->optionsFor($this->bulkTargetProject) as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @elseif (in_array($field->field_format, [\App\Enums\CustomFieldFormat::List, \App\Enums\CustomFieldFormat::Enumeration, \App\Enums\CustomFieldFormat::Bool], true))
                                <select wire:model="bulkCustomFieldValues.{{ $field->id }}" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                                    <option value="">{{ __('変更なし') }}</option>
                                    @if ($field->field_format === \App\Enums\CustomFieldFormat::Bool)
                                        <option value="1">{{ __('はい') }}</option>
                                        <option value="0">{{ __('いいえ') }}</option>
                                    @else
                                        @foreach ($field->format()->options($field) as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            @else
                                <input type="text" wire:model="bulkCustomFieldValues.{{ $field->id }}" placeholder="{{ __('変更なし') }}" class="mt-1 block w-full rounded-md border-neutral-300 text-sm">
                            @endif
                            @error("bulkCustomFieldValues.{$field->id}") <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            @endif

            <div>
                <label class="block text-xs font-medium text-neutral-700">{{ __('コメント(変更する場合のみ入力)') }}</label>
                <textarea wire:model="bulkComments" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 text-sm"></textarea>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    {{ __('一括更新') }}
                </button>
                <button type="button" wire:click="applyBulkDelete" wire:confirm="{{ __('選択した:count件の工数記録を削除します。この操作は取り消せません。よろしいですか?', ['count' => count($selected)]) }}"
                    class="rounded-md border border-danger-subtle px-3 py-2 text-sm font-medium text-danger-bolder hover:bg-danger-subtlest">
                    {{ __('選択した:count件を削除', ['count' => count($selected)]) }}
                </button>
                <button type="button" wire:click="$set('selected', [])" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-surface">
                    {{ __('選択解除') }}
                </button>
            </div>
        </form>
    @endif

    @foreach ($this->groupedTimeEntries as $groupLabel => $groupEntries)
        @php $groupKey = $groupLabel !== '' ? $groupLabel : '__ungrouped__'; @endphp
        @if ($groupBy !== null)
            <h2 wire:key="group-heading-{{ $groupKey }}" class="mb-2 mt-4 text-sm font-semibold text-neutral-900">
                {{ $groupLabel ?: __('(未設定)') }} {{ __('(:count件 / :hours 時間)', ['count' => $this->groupSubtotals[$groupLabel]['count'] ?? $groupEntries->count(), 'hours' => $this->groupSubtotals[$groupLabel]['hours'] ?? '0']) }}
            </h2>
        @endif

        <div wire:key="group-table-{{ $groupKey }}" class="overflow-x-auto rounded-md border border-neutral-200 bg-surface mb-4">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
                    <tr>
                        @if ($this->canManage)
                            <th class="px-4 py-2"></th>
                        @endif
                        @foreach ($columns as $columnKey)
                            <th wire:key="column-heading-{{ $columnKey }}" class="px-4 py-2">
                                <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-neutral-900">
                                    {{ $this->availableColumns[$columnKey] ?? $columnKey }}
                                    @if ($sortKey === $columnKey)
                                        <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        @if ($this->canManage)
                            <th class="px-4 py-2"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($groupEntries as $entry)
                        @php($ownEntry = $entry->project_id === $project->id)
                        <tr wire:key="time-entry-{{ $entry->id }}" @if ($ownEntry) @can('update', $entry) x-on:contextmenu.prevent="showMenu($event, {{ $entry->id }})" @endcan @endif class="{{ in_array((string) $entry->id, array_map('strval', $selected), true) ? 'bg-brand-subtlest' : '' }}">
                            @if ($this->canManage)
                                <td class="px-4 py-2">
                                    @if ($ownEntry)
                                        @can('update', $entry)
                                            <input type="checkbox" wire:model="selected" value="{{ $entry->id }}" class="rounded border-neutral-300">
                                        @endcan
                                    @endif
                                </td>
                            @endif
                            @foreach ($columns as $columnKey)
                                <td wire:key="time-entry-{{ $entry->id }}-column-{{ $columnKey }}" class="px-4 py-2">
                                    @if ($columnKey === 'issue_id')
                                        @if ($entry->issue && $this->issueIsVisible($entry))
                                            <a href="{{ route('issues.show', [$entry->issue->project, $entry->issue]) }}" class="text-brand-bold hover:underline">
                                                #{{ $entry->issue->id }} {{ $entry->issue->subject }}
                                            </a>
                                        @elseif ($entry->issue)
                                            #{{ $entry->issue->id }}
                                        @else
                                            -
                                        @endif
                                    @else
                                        {{ $this->columnValue($entry, $columnKey) }}
                                    @endif
                                </td>
                            @endforeach
                            @if ($this->canManage)
                                <td class="px-4 py-2 whitespace-nowrap">
                                    @can('update', $entry)
                                        <a href="{{ route('time-entries.edit', [$entry->project, $entry]) }}" class="text-brand-bold hover:underline">{{ __('編集') }}</a>
                                        @if ($ownEntry)
                                            <button wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('この工数記録を削除しますか?') }}" class="ml-2 text-danger-bolder hover:underline">{{ __('削除') }}</button>
                                        @endif
                                    @endcan
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + ($this->canManage ? 2 : 0) }}" class="px-4 py-6 text-center text-neutral-500">{{ __('工数記録がありません。') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->timeEntries->perPage()" :total="$this->timeEntries->total()" /></div>
    {{ $this->timeEntries->links() }}
</div>