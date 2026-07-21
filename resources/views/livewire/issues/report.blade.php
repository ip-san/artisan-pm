<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    /**
     * Raw (dimension_value, status_id) => count for every issue in this
     * project, grouped by the given column — matches Redmine's
     * Issue.count_and_group_by. Fetched once per dimension and pivoted in
     * PHP rather than joining in SQL, since the "row" side (tracker,
     * priority, category, ...) comes from a different table per dimension.
     *
     * @return array<int|string, array<int, int>> dimension value (or 'none') => status_id => count
     */
    private function countsByColumn(string $column): array
    {
        $rows = Issue::query()
            ->where('project_id', $this->project->id)
            ->selectRaw("{$column} as dimension_value, status_id, COUNT(*) as total")
            ->groupBy($column, 'status_id')
            ->get();

        $pivoted = [];

        foreach ($rows as $row) {
            $key = $row->dimension_value ?? 'none';
            $pivoted[$key][$row->status_id] = (int) $row->total;
        }

        return $pivoted;
    }

    /**
     * @param  Collection<int, object{id: int, name: string}>  $rows
     * @param  array<int|string, array<int, int>>  $counts
     * @return array{rows: array<int, array{key: int|string, label: string}>, counts: array<int|string, array<int, int>>}
     */
    private function buildGrid(Collection $rows, array $counts, bool $withNoneRow): array
    {
        $gridRows = $rows->map(fn ($row) => ['key' => $row->id, 'label' => $row->name])->all();

        if ($withNoneRow && array_key_exists('none', $counts)) {
            $gridRows[] = ['key' => 'none', 'label' => 'なし'];
        }

        return ['rows' => $gridRows, 'counts' => $counts];
    }

    #[Computed]
    public function trackerGrid(): array
    {
        return $this->buildGrid($this->project->trackers, $this->countsByColumn('tracker_id'), false);
    }

    #[Computed]
    public function priorityGrid(): array
    {
        $priorities = Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get();

        return $this->buildGrid($priorities, $this->countsByColumn('priority_id'), false);
    }

    #[Computed]
    public function categoryGrid(): array
    {
        return $this->buildGrid($this->project->issueCategories, $this->countsByColumn('category_id'), true);
    }

    #[Computed]
    public function versionGrid(): array
    {
        return $this->buildGrid($this->project->versions, $this->countsByColumn('fixed_version_id'), true);
    }

    #[Computed]
    public function assigneeGrid(): array
    {
        $assignees = $this->project->assignableUsers();

        return $this->buildGrid($assignees, $this->countsByColumn('assigned_to_id'), true);
    }

    #[Computed]
    public function authorGrid(): array
    {
        /** @var Collection<int, User> $authors */
        $authors = $this->project->users;

        return $this->buildGrid($authors, $this->countsByColumn('author_id'), false);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — 課題レポート</h1>

    @php
        $sections = [
            'トラッカー別' => $this->trackerGrid,
            '優先度別' => $this->priorityGrid,
            'カテゴリ別' => $this->categoryGrid,
            '対象バージョン別' => $this->versionGrid,
            '担当者別' => $this->assigneeGrid,
            '作成者別' => $this->authorGrid,
        ];
    @endphp

    <div class="space-y-8">
        @foreach ($sections as $title => $grid)
            <div class="overflow-x-auto">
                <h2 class="mb-2 text-sm font-semibold text-gray-900">{{ $title }}</h2>
                <table class="min-w-full border border-gray-200 bg-white text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="px-3 py-2 text-left font-medium text-gray-700"></th>
                            @foreach ($this->statuses as $status)
                                <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $status->name }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-semibold text-gray-900">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grid['rows'] as $row)
                            <tr class="border-b border-gray-100">
                                <td class="px-3 py-2 text-gray-900">{{ $row['label'] }}</td>
                                @foreach ($this->statuses as $status)
                                    <td class="px-3 py-2 text-right text-gray-700">
                                        {{ $grid['counts'][$row['key']][$status->id] ?? 0 }}
                                    </td>
                                @endforeach
                                <td class="px-3 py-2 text-right font-semibold text-gray-900">
                                    {{ array_sum($grid['counts'][$row['key']] ?? []) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->statuses->count() + 2 }}" class="px-3 py-4 text-center text-gray-500">
                                    データがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</div>
