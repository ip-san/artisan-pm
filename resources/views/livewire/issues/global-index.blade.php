<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Redmine's top-level IssuesController#index (no project_id) — every
 * issue across every project the current user can view_issues in, in one
 * filterable/sortable list. Unlike the project-scoped issues.index, this
 * intentionally has no bulk edit/move/copy/delete (those assume a single
 * project's members/versions/trackers to validate against), no CSV
 * export, no grouping, and only a single sort level rather than three —
 * all tracked as separate, smaller follow-ups in docs/parity-checklist.md
 * rather than folded into this one. Saved queries here are always global
 * (`project_id IS NULL`, matching Redmine's `query_is_for_all` on the
 * cross-project index) — loading one only restores filters/columns/the
 * primary sort key, since grouping and the 2nd/3rd sort levels don't
 * exist on this page.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;
    use WithPagination;

    /**
     * @var array<string, string>
     */
    public const array DISPLAY_COLUMNS = [
        'project_id' => 'プロジェクト',
        'tracker_id' => 'トラッカー',
        'status_id' => 'ステータス',
        'priority_id' => '優先度',
        'subject' => '題名',
        'assigned_to_id' => '担当者',
        'author_id' => '作成者',
        'fixed_version_id' => '対象バージョン',
        'start_date' => '開始日',
        'due_date' => '期日',
        'created_at' => '作成日',
        'done_ratio' => '進捗率',
    ];

    #[Url]
    public string $statusFilter = 'open';

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['project_id', 'tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id'];

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * Every project the current user can view issues in at all —
     * resolved once per render the same way news.global-index resolves
     * visible projects, then reused both to build the filter engine's
     * options and to scope the issue query itself.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->with(['trackers', 'issueCategories', 'users', 'versions'])
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('viewAny', [Issue::class, $project]))
            ->values();
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProjects($this->visibleProjects));
    }

    /**
     * @return Builder<Issue>
     */
    private function filteredIssuesQuery(): Builder
    {
        $query = Issue::query()
            ->visibleToAcrossProjects(auth()->user(), $this->visibleProjects)
            ->with(['project', 'tracker', 'status', 'priority', 'assignedTo', 'author', 'fixedVersion'])
            ->when(
                collect($this->columns)->contains(fn (string $column) => str_starts_with($column, 'cf_')),
                fn (Builder $q) => $q->with('customFieldValues.customField')
            );

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
        return $this->filteredIssuesQuery()->paginate(25);
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

    public function columnValue(Issue $issue, string $key): string
    {
        if (str_starts_with($key, 'cf_')) {
            $fieldId = (int) substr($key, 3);

            return $issue->customFieldValues
                ->where('custom_field_id', $fieldId)
                ->map(fn ($value) => $value->value())
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->join(', ');
        }

        return match ($key) {
            'project_id' => $issue->project->name,
            'tracker_id' => $issue->tracker->name,
            'status_id' => $issue->status->name,
            'priority_id' => $issue->priority->name,
            'subject' => $issue->subject,
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

    public function applyFilters(): void
    {
        $this->resetPage();
        unset($this->issues);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        unset($this->issues);
    }

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_admin;
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleGlobally(QueryType::Issue, auth()->user());
    }

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], null);

        $query = SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::Issue->value,
            'user_id' => auth()->id(),
            'project_id' => null,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => $this->columns,
            'sort_criteria' => [[$this->sortKey, $this->sortDirection]],
            'group_by' => null,
        ]);

        if ($visibility === QueryVisibility::Roles->value) {
            $query->roles()->sync($data['newQueryRoleIds']);
        }

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', 'クエリを保存しました。');
    }

    public function loadQuery(int $queryId): void
    {
        $query = SavedQuery::query()->whereNull('project_id')->findOrFail($queryId);

        abort_unless($query->visibleTo(auth()->user()), 403);

        $this->activeFilterKeys = array_keys($query->filters);
        $this->filterOperators = [];
        $this->filterValues = [];

        foreach ($query->filters as $key => $filter) {
            $this->filterOperators[$key] = $filter['operator'];
            $this->filterValues[$key] = $filter['values'] ?? [];
        }

        $this->columns = $query->column_names;

        $criteria = $query->sort_criteria ?? [];

        if (isset($criteria[0])) {
            [$this->sortKey, $this->sortDirection] = $criteria[0];
        }

        $this->resetPage();
        unset($this->issues);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">課題(全プロジェクト)</h1>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 text-sm shadow-sm">
            <option value="open">未完了の課題</option>
            <option value="closed">完了した課題</option>
            <option value="all">すべての課題</option>
        </select>
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

    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                適用
            </button>

            <button wire:click="$toggle('showSaveForm')" class="text-sm text-indigo-600 hover:underline">クエリを保存</button>
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles" />
        @endif
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                <tr>
                    @foreach ($columns as $column)
                        <th class="px-3 py-2">
                            <button wire:click="sortBy('{{ $column }}')" class="flex items-center gap-1 hover:text-gray-900">
                                {{ self::DISPLAY_COLUMNS[$column] ?? $column }}
                                @if ($sortKey === $column)
                                    <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->issues as $issue)
                    <tr wire:key="issue-{{ $issue->id }}" class="hover:bg-gray-50">
                        @foreach ($columns as $column)
                            <td class="px-3 py-2 text-gray-700">
                                @if ($column === 'subject')
                                    <a href="{{ route('issues.show', [$issue->project, $issue]) }}" class="text-indigo-600 hover:underline">
                                        #{{ $issue->id }} {{ $this->columnValue($issue, $column) }}
                                    </a>
                                @else
                                    {{ $this->columnValue($issue, $column) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" class="px-3 py-6 text-center text-gray-500">課題がありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->issues->links() }}
    </div>
</div>
