<?php

use App\Enums\EnumerationType;
use App\Enums\FilterOperator;
use App\Enums\IssueVisibility;
use App\Enums\QueryType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Setting;
use App\Models\Tracker;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    /**
     * Columns selectable for display/CSV export. Custom fields are already
     * filterable (see IssueFilterFieldRegistry) but not yet offered as a
     * display column — rendering an EAV value generically is deferred.
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
    ];

    public Project $project;

    #[Url]
    public string $statusFilter = 'open';

    /** @var array<int, string> */
    #[Url]
    public array $activeFilterKeys = [];

    /** @var array<string, string> */
    public array $filterOperators = [];

    /** @var array<string, array<int, mixed>> */
    public array $filterValues = [];

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id'];

    public string $csvEncoding = 'UTF-8';

    public string $csvSeparator = ',';

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    #[Url]
    public ?string $groupBy = null;

    public string $newQueryName = '';

    public bool $newQueryIsPublic = false;

    public bool $showSaveForm = false;

    /** @var array<int, int> */
    public array $selected = [];

    public ?int $bulkPriorityId = null;

    public ?int $bulkAssignedToId = null;

    public ?int $bulkFixedVersionId = null;

    public ?int $bulkStatusId = null;

    public ?int $bulkDoneRatio = null;

    public string $bulkComment = '';

    public ?int $bulkMoveToProjectId = null;

    public ?int $bulkMoveToTrackerId = null;

    public ?int $bulkCopyToProjectId = null;

    public ?int $bulkCopyToTrackerId = null;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project));
    }

    /**
     * @return Builder<Issue>
     */
    private function filteredIssuesQuery(): Builder
    {
        $query = Issue::query()
            ->where('project_id', $this->project->id)
            ->with(['tracker', 'status', 'priority', 'category', 'assignedTo', 'author', 'fixedVersion']);

        $userId = auth()->id();

        match (app(AuthorizationService::class)->issueVisibilityFor(auth()->user(), $this->project)) {
            IssueVisibility::All => null,
            IssueVisibility::Default => $query->where(fn ($q) => $q->where('is_private', false)
                ->orWhere('author_id', $userId)
                ->orWhere('assigned_to_id', $userId)),
            IssueVisibility::Own => $query->where(fn ($q) => $q->where('author_id', $userId)->orWhere('assigned_to_id', $userId)),
        };

        if ($this->statusFilter !== 'all') {
            $isClosed = $this->statusFilter === 'closed';
            $query->whereHas('status', fn ($q) => $q->where('is_closed', $isClosed));
        }

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        if ($this->sortKey !== null) {
            $query = $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
        } else {
            $query->orderByDesc('id');
        }

        return $query;
    }

    /**
     * @return LengthAwarePaginator<int, Issue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return $this->filteredIssuesQuery()->paginate(Setting::get('default_issues_per_page', 25));
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
     * just the current page), computed via a SQL GROUP BY. Only offered
     * for the groupBy select's options (status/tracker/priority/assigned
     * to), all plain FK columns on `issues`, so grouping directly by the
     * raw column name is safe — $groupBy is checked against the known
     * column whitelist before it ever reaches raw SQL.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function groupTotals(): Collection
    {
        if ($this->groupBy === null || ! array_key_exists($this->groupBy, self::DISPLAY_COLUMNS)) {
            return collect();
        }

        $column = $this->groupBy;
        $options = $this->engine->field($column)?->options() ?? [];
        $nullLabel = $column === 'assigned_to_id' ? '未割当' : '';

        return $this->filteredIssuesQuery()
            ->reorder()
            ->selectRaw("{$column} as group_key, COUNT(*) as total")
            ->groupBy($column)
            ->pluck('total', 'group_key')
            ->mapWithKeys(fn ($total, $rawKey) => [
                ($rawKey === null || $rawKey === '') ? $nullLabel : ($options[$rawKey] ?? (string) $rawKey) => (int) $total,
            ]);
    }

    /**
     * @return array<string, array{operator: string, values: array<int, mixed>}>
     */
    private function builtFilters(): array
    {
        $filters = [];

        foreach ($this->activeFilterKeys as $key) {
            $operator = $this->filterOperators[$key] ?? null;

            if ($operator === null) {
                continue;
            }

            $filters[$key] = [
                'operator' => $operator,
                'values' => array_values(array_filter($this->filterValues[$key] ?? [], fn ($v) => $v !== null && $v !== '')),
            ];
        }

        return $filters;
    }

    public function addFilter(string $key): void
    {
        if (! in_array($key, $this->activeFilterKeys, true) && $this->engine->field($key) !== null) {
            $this->activeFilterKeys[] = $key;
            $this->filterOperators[$key] = $this->engine->field($key)->operators()[0]->value;
        }
    }

    public function removeFilter(string $key): void
    {
        $this->activeFilterKeys = array_values(array_diff($this->activeFilterKeys, [$key]));
        unset($this->filterOperators[$key], $this->filterValues[$key]);
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

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryIsPublic' => ['boolean'],
        ]);

        SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::Issue->value,
            'user_id' => auth()->id(),
            'project_id' => $this->project->id,
            'is_public' => $data['newQueryIsPublic'],
            'filters' => $this->builtFilters(),
            'column_names' => $this->columns,
            'sort_criteria' => $this->sortKey ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => $this->groupBy,
        ]);

        $this->reset(['newQueryName', 'newQueryIsPublic', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', 'クエリを保存しました。');
    }

    public function loadQuery(int $queryId): void
    {
        $query = SavedQuery::query()->where('project_id', $this->project->id)->findOrFail($queryId);

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

        if ($query->sort_criteria !== [] && $query->sort_criteria !== null) {
            [$this->sortKey, $this->sortDirection] = $query->sort_criteria[0];
        }

        $this->resetPage();
        unset($this->issues, $this->groupedIssues, $this->groupTotals);
    }

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::query()
            ->where('project_id', $this->project->id)
            ->where('type', QueryType::Issue->value)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhere('is_public', true))
            ->orderBy('name')
            ->get();
    }

    public function columnValue(Issue $issue, string $key): string
    {
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

            $writeRow(array_map(fn ($key) => self::DISPLAY_COLUMNS[$key] ?? $key, $columns));

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

        if ($issues->isEmpty() || $issues->pluck('status_id')->unique()->count() > 1) {
            return collect();
        }

        return app(WorkflowService::class)->allowedTransitions($issues->first(), auth()->user());
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
            'bulkComment' => ['nullable', 'string'],
        ]);

        $changes = array_filter([
            'priority_id' => $data['bulkPriorityId'],
            'assigned_to_id' => $data['bulkAssignedToId'],
            'fixed_version_id' => $data['bulkFixedVersionId'],
            'status_id' => $data['bulkStatusId'],
            'done_ratio' => $data['bulkDoneRatio'],
        ], fn ($value) => $value !== null);

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

        $this->reset(['selected', 'bulkPriorityId', 'bulkAssignedToId', 'bulkFixedVersionId', 'bulkStatusId', 'bulkDoneRatio', 'bulkComment']);
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を更新しました。");
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

        return $target?->trackers ?? collect();
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

    public function applyBulkDelete(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('delete', $issue);
        }

        $count = $issues->count();

        foreach ($issues as $issue) {
            $issue->delete();
        }

        $this->reset('selected');
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

        return $target?->trackers ?? collect();
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
            app(IssueService::class)->copy($issue, $targetProject, $data['bulkCopyToTrackerId'], auth()->user());
        }

        $count = $issues->count();

        $this->reset(['selected', 'bulkCopyToProjectId', 'bulkCopyToTrackerId']);
        $this->resetPage();
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions, $this->groupedIssues, $this->groupTotals);

        session()->flash('status', "{$count}件の課題を「{$targetProject->name}」へ複製しました。");
    }
}; ?>

<div>
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
            @can('create', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.import', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    CSVインポート
                </a>
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
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-gray-700">フィルタを追加:</span>
            @foreach ($this->engine->fields() as $field)
                @unless (in_array($field->key(), $activeFilterKeys, true))
                    <button wire:key="add-filter-{{ $field->key() }}" wire:click="addFilter('{{ $field->key() }}')" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">
                        + {{ $field->label() }}
                    </button>
                @endunless
            @endforeach
        </div>

        @if ($activeFilterKeys !== [])
            <div class="space-y-2">
                @foreach ($activeFilterKeys as $key)
                    @php $field = $this->engine->field($key); @endphp
                    @continue(! $field)
                    <div wire:key="filter-row-{{ $key }}" class="flex flex-wrap items-center gap-2">
                        <span class="w-28 text-sm text-gray-700">{{ $field->label() }}</span>
                        <select wire:model="filterOperators.{{ $key }}" class="rounded-md border-gray-300 text-sm">
                            @foreach ($field->operators() as $operator)
                                <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                            @endforeach
                        </select>

                        @if (($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsEmpty->value && ($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsNotEmpty->value)
                            @if ($field->type() === \App\Enums\FilterFieldType::Select && $field->options() !== [])
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::In->value || ($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::NotIn->value)
                                    <select wire:model="filterValues.{{ $key }}" multiple class="min-w-[10rem] rounded-md border-gray-300 text-sm">
                                        @foreach ($field->options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <select wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                                        <option value="">選択してください</option>
                                        @foreach ($field->options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            @elseif ($field->type() === \App\Enums\FilterFieldType::Date)
                                <input type="date" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                                    <span class="text-gray-400">〜</span>
                                    <input type="date" wire:model="filterValues.{{ $key }}.1" class="rounded-md border-gray-300 text-sm">
                                @endif
                            @elseif ($field->type() === \App\Enums\FilterFieldType::Integer)
                                <input type="number" wire:model="filterValues.{{ $key }}.0" class="w-24 rounded-md border-gray-300 text-sm">
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                                    <span class="text-gray-400">〜</span>
                                    <input type="number" wire:model="filterValues.{{ $key }}.1" class="w-24 rounded-md border-gray-300 text-sm">
                                @endif
                            @else
                                <input type="text" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                            @endif
                        @endif

                        <button wire:click="removeFilter('{{ $key }}')" class="text-xs text-red-600 hover:underline">削除</button>
                    </div>
                @endforeach
            </div>
        @endif

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
                </select>
            </label>

            <div class="flex items-center gap-2 text-sm text-gray-700">
                表示列:
                @foreach (self::DISPLAY_COLUMNS as $key => $label)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="columns" value="{{ $key }}" class="rounded border-gray-300">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            <button wire:click="$toggle('showSaveForm')" class="text-sm text-indigo-600 hover:underline">クエリを保存</button>
        </div>

        @if ($showSaveForm)
            <form wire:submit="saveQuery" class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                <input type="text" wire:model="newQueryName" placeholder="クエリ名" class="rounded-md border-gray-300 text-sm">
                <label class="flex items-center gap-1 text-sm text-gray-700">
                    <input type="checkbox" wire:model="newQueryIsPublic" class="rounded border-gray-300">
                    公開する
                </label>
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">保存</button>
                @error('newQueryName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </form>
        @endif
    </div>

    @if ($this->canBulkEdit && count($selected) > 0)
        <form wire:submit="applyBulkEdit" class="mb-4 space-y-3 rounded-md border border-indigo-200 bg-indigo-50 p-4">
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
                    <label class="block text-xs font-medium text-gray-700">進捗率</label>
                    <select wire:model="bulkDoneRatio" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ratio)
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
        <form wire:submit="applyBulkCopy" class="mb-4 flex flex-wrap items-end gap-2 rounded-md border border-gray-200 bg-white p-4">
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
                <button type="submit"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    複製
                </button>
            @endif
        </form>
    @endif

    @if (count($selected) > 0 && auth()->user()?->can('delete', $this->selectedIssues->first()))
        <div class="mb-4">
            <button type="button" wire:click="applyBulkDelete"
                wire:confirm="選択した{{ count($selected) }}件の課題を削除します。この操作は取り消せません。よろしいですか?"
                class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                選択した{{ count($selected) }}件を削除
            </button>
        </div>
    @endif

    @foreach ($this->groupedIssues as $groupLabel => $groupIssues)
        @php $groupKey = $groupLabel !== '' ? $groupLabel : '__ungrouped__'; @endphp
        @if ($groupBy !== null)
            <h2 wire:key="group-heading-{{ $groupKey }}" class="mb-2 mt-4 text-sm font-semibold text-gray-900">{{ $groupLabel ?: '(未設定)' }} ({{ $this->groupTotals[$groupLabel] ?? $groupIssues->count() }})</h2>
        @endif

        <div wire:key="group-table-{{ $groupKey }}" class="overflow-x-auto rounded-md border border-gray-200 bg-white mb-4">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        @if ($this->canBulkEdit)
                            <th class="px-4 py-2"></th>
                        @endif
                        <th class="px-4 py-2">#</th>
                        @foreach ($columns as $columnKey)
                            <th wire:key="column-heading-{{ $columnKey }}" class="px-4 py-2">
                                <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-gray-900">
                                    {{ self::DISPLAY_COLUMNS[$columnKey] ?? $columnKey }}
                                    @if ($sortKey === $columnKey)
                                        <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groupIssues as $issue)
                        <tr wire:key="issue-row-{{ $issue->id }}">
                            @if ($this->canBulkEdit)
                                <td class="px-4 py-2">
                                    <input type="checkbox" wire:model="selected" value="{{ $issue->id }}" class="rounded border-gray-300">
                                </td>
                            @endif
                            <td class="px-4 py-2 text-gray-500">{{ $issue->id }}</td>
                            @foreach ($columns as $columnKey)
                                <td wire:key="issue-{{ $issue->id }}-column-{{ $columnKey }}" class="px-4 py-2">
                                    @if ($columnKey === 'subject')
                                        <a href="{{ route('issues.show', [$project, $issue]) }}" class="text-indigo-600 hover:underline">
                                            {{ $issue->subject }}
                                        </a>
                                    @else
                                        {{ $this->columnValue($issue, $columnKey) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 2 }}" class="px-4 py-6 text-center text-gray-500">課題がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    {{ $this->issues->links() }}
</div>
