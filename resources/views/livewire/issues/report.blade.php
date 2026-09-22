<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Support\Reports\IssueReport;
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

    #[Computed]
    public function report(): IssueReport
    {
        return new IssueReport($this->project, auth()->user());
    }

    /**
     * @return array{rows: array<int, array{key: int|string, label: string}>, counts: array<int|string, array<int, int>>}
     */
    private function grid(string $dimension): array
    {
        $counts = $this->report->counts($dimension);

        return ['rows' => $this->report->gridRows($dimension, $counts), 'counts' => $counts];
    }

    #[Computed]
    public function trackerGrid(): array
    {
        return $this->grid('tracker');
    }

    #[Computed]
    public function priorityGrid(): array
    {
        return $this->grid('priority');
    }

    #[Computed]
    public function categoryGrid(): array
    {
        return $this->grid('category');
    }

    #[Computed]
    public function versionGrid(): array
    {
        return $this->grid('version');
    }

    #[Computed]
    public function assigneeGrid(): array
    {
        return $this->grid('assigned_to');
    }

    #[Computed]
    public function authorGrid(): array
    {
        return $this->grid('author');
    }

    #[Computed]
    public function subprojectGrid(): array
    {
        return $this->grid('subproject');
    }

    /**
     * Deep-links a grid cell (or a row's 合計 column, when $statusId is
     * null) to the pre-filtered issue list — matches Redmine's
     * aggregate_link in reports/_details.html.erb. 'none' rows (no
     * category/version/assignee set) filter with the "empty" operator
     * instead of "=", since there's no id to match against.
     */
    private function cellUrl(string $column, int|string $rowKey, ?int $statusId): string
    {
        // A subproject row opens that subproject's own list; the rows are
        // projects, not values of a filter on this project's list.
        if ($column === 'project_id') {
            return route('issues.index', [
                Project::query()->findOrFail($rowKey),
                'statusFilter' => 'all',
                ...($statusId !== null ? [
                    'activeFilterKeys' => ['status_id'],
                    'filterOperators' => ['status_id' => '='],
                    'filterValues' => ['status_id' => [$statusId]],
                ] : []),
            ]);
        }

        $activeFilterKeys = [$column];
        $filterOperators = [$column => $rowKey === 'none' ? 'empty' : '='];
        $filterValues = [$column => $rowKey === 'none' ? [] : [$rowKey]];

        if ($statusId !== null) {
            $activeFilterKeys[] = 'status_id';
            $filterOperators['status_id'] = '=';
            $filterValues['status_id'] = [$statusId];
        }

        return route('issues.index', [
            $this->project,
            'statusFilter' => 'all',
            'activeFilterKeys' => $activeFilterKeys,
            'filterOperators' => $filterOperators,
            'filterValues' => $filterValues,
        ]);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ $project->name }} — 課題レポート</h1>

    @php
        $sections = [
            ['title' => 'トラッカー別', 'column' => 'tracker_id', 'grid' => $this->trackerGrid],
            ['title' => '優先度別', 'column' => 'priority_id', 'grid' => $this->priorityGrid],
            ['title' => 'カテゴリ別', 'column' => 'category_id', 'grid' => $this->categoryGrid],
            ['title' => '対象バージョン別', 'column' => 'fixed_version_id', 'grid' => $this->versionGrid],
            ['title' => '担当者別', 'column' => 'assigned_to_id', 'grid' => $this->assigneeGrid],
            ['title' => '作成者別', 'column' => 'author_id', 'grid' => $this->authorGrid],
            ...(in_array('subproject', $this->report->dimensions(), true) ? [['title' => 'サブプロジェクト別', 'column' => 'project_id', 'grid' => $this->subprojectGrid]] : []),
        ];
        $detailKeys = ['tracker_id' => 'tracker', 'priority_id' => 'priority', 'category_id' => 'category', 'version' => 'version', 'fixed_version_id' => 'version', 'assigned_to_id' => 'assigned_to', 'author_id' => 'author', 'project_id' => 'subproject'];
    @endphp

    <div class="space-y-8">
        @foreach ($sections as $section)
            @php $grid = $section['grid']; @endphp
            <div class="overflow-x-auto">
                <h2 class="mb-2 text-sm font-semibold text-neutral-900">
                    {{ $section['title'] }}
                    <a href="{{ route('issues.report-details', [$project, $detailKeys[$section['column']]]) }}" class="ml-2 text-xs font-normal text-brand-bold hover:underline">詳細</a>
                </h2>
                <table class="min-w-full border border-neutral-200 bg-white text-sm">
                    <thead>
                        <tr class="border-b border-neutral-200 bg-neutral-50">
                            <th class="px-3 py-2 text-left font-medium text-neutral-700"></th>
                            @foreach ($this->statuses as $status)
                                <th class="px-3 py-2 text-right font-medium text-neutral-700">{{ $status->name }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-semibold text-neutral-900">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grid['rows'] as $row)
                            @php $rowTotal = array_sum($grid['counts'][$row['key']] ?? []); @endphp
                            <tr class="border-b border-neutral-100">
                                <td class="px-3 py-2 text-neutral-900">{{ $row['label'] }}</td>
                                @foreach ($this->statuses as $status)
                                    @php $count = $grid['counts'][$row['key']][$status->id] ?? 0; @endphp
                                    <td class="px-3 py-2 text-right text-neutral-700">
                                        @if ($count > 0)
                                            <a href="{{ $this->cellUrl($section['column'], $row['key'], $status->id) }}" class="text-brand-bold hover:underline">{{ $count }}</a>
                                        @else
                                            {{ $count }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2 text-right font-semibold text-neutral-900">
                                    @if ($rowTotal > 0)
                                        <a href="{{ $this->cellUrl($section['column'], $row['key'], null) }}" class="text-brand-bold hover:underline">{{ $rowTotal }}</a>
                                    @else
                                        {{ $rowTotal }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->statuses->count() + 2 }}" class="px-3 py-4 text-center text-neutral-500">
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
