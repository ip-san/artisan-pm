<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 * export, no saved queries (Query::visibleIn() has no cross-project
 * variant yet), and only a single sort level rather than three — all
 * tracked as separate, smaller follow-ups in docs/parity-checklist.md
 * rather than folded into this one.
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

    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                適用
            </button>
        </div>
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
