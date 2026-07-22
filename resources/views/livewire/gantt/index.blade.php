<?php

use App\Models\Issue;
use App\Models\Project;
use App\Services\GanttService;
use App\Support\Gantt\GanttRow;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    /** @var array<int, string> */
    #[Url]
    public array $activeFilterKeys = [];

    /** @var array<string, string> */
    public array $filterOperators = [];

    /** @var array<string, array<int, mixed>> */
    public array $filterValues = [];

    public function mount(Project $project): void
    {
        $this->authorize('viewGantt', $project);

        $this->project = $project;
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project));
    }

    /**
     * With filters active, the tree is restricted to matching issues
     * (their ancestors stay for depth coherence — see GanttService).
     * The matched-id set is resolved through the same QueryFilterEngine
     * the issue list uses; the recursive-CTE tree query itself stays
     * untouched.
     *
     * @return Collection<int, GanttRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        $filters = $this->builtFilters();

        $matchedIds = $filters === []
            ? null
            : $this->engine->applyFilters(Issue::query()->where('project_id', $this->project->id), $filters)->pluck('id');

        return app(GanttService::class)->issueTree($this->project, $matchedIds);
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
        unset($this->rows, $this->rangeStart, $this->rangeEnd, $this->totalDays, $this->monthBands);
    }

    #[Computed]
    public function rangeStart(): ?Carbon
    {
        return $this->rows->pluck('startDate')->filter()->min();
    }

    #[Computed]
    public function rangeEnd(): ?Carbon
    {
        return $this->rows->pluck('dueDate')->filter()->max();
    }

    #[Computed]
    public function totalDays(): int
    {
        if ($this->rangeStart === null || $this->rangeEnd === null) {
            return 0;
        }

        return max(1, (int) $this->rangeStart->diffInDays($this->rangeEnd) + 1);
    }

    /**
     * @return array<int, array{label: string, leftPercent: float, widthPercent: float}>
     */
    #[Computed]
    public function monthBands(): array
    {
        if ($this->rangeStart === null || $this->rangeEnd === null) {
            return [];
        }

        $bands = [];
        $cursor = $this->rangeStart->copy()->startOfMonth();

        while ($cursor->lte($this->rangeEnd)) {
            $bandStart = $cursor->max($this->rangeStart);
            $bandEnd = $cursor->copy()->endOfMonth()->min($this->rangeEnd);

            $bands[] = [
                'label' => $cursor->format('Y-m'),
                'leftPercent' => $this->percentFromStart($bandStart),
                'widthPercent' => $this->percentWidth($bandStart, $bandEnd),
            ];

            $cursor->addMonthNoOverflow();
        }

        return $bands;
    }

    public function barLeftPercent(GanttRow $row): float
    {
        return $this->percentFromStart($row->startDate ?? throw new LogicException('Gantt row is missing a start date.'));
    }

    public function barWidthPercent(GanttRow $row): float
    {
        return $this->percentWidth(
            $row->startDate ?? throw new LogicException('Gantt row is missing a start date.'),
            $row->dueDate ?? throw new LogicException('Gantt row is missing a due date.'),
        );
    }

    private function percentFromStart(Carbon $date): float
    {
        return $this->rangeStart->diffInDays($date) / $this->totalDays * 100;
    }

    private function percentWidth(Carbon $from, Carbon $to): float
    {
        return (($from->diffInDays($to) + 1) / $this->totalDays) * 100;
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — ガントチャート</h1>

    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                絞り込み適用
            </button>
        </div>
    </div>

    @if ($this->rangeStart === null)
        <p class="text-sm text-gray-500">開始日・期日が設定された課題がありません。</p>
    @else
        <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
            <div class="flex min-w-[900px]">
                <div class="w-80 shrink-0 border-r border-gray-200">
                    <div class="h-8 border-b border-gray-200 bg-gray-50"></div>
                    @foreach ($this->rows as $row)
                        <div wire:key="label-{{ $row->id }}" class="flex h-8 items-center border-b border-gray-100 px-2 text-sm"
                            style="padding-left: {{ 8 + $row->depth * 16 }}px">
                            <a href="{{ route('issues.show', [$project, $row->id]) }}" class="truncate text-indigo-600 hover:underline">
                                {{ $row->trackerName }} #{{ $row->id }}: {{ $row->subject }}
                            </a>
                        </div>
                    @endforeach
                </div>

                <div class="relative flex-1">
                    <div class="relative h-8 border-b border-gray-200 bg-gray-50 text-xs text-gray-500">
                        @foreach ($this->monthBands as $band)
                            <div class="absolute top-0 flex h-8 items-center border-l border-gray-200 pl-1"
                                style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%">
                                {{ $band['label'] }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($this->rows as $row)
                        <div wire:key="row-{{ $row->id }}" class="relative h-8 border-b border-gray-100">
                            @if ($row->hasDateRange())
                                <div class="absolute top-1.5 h-5 rounded {{ $row->isClosed ? 'bg-gray-400' : 'bg-indigo-400' }}"
                                    style="left: {{ $this->barLeftPercent($row) }}%; width: {{ $this->barWidthPercent($row) }}%"
                                    title="{{ $row->subject }} ({{ $row->startDate->toDateString() }} 〜 {{ $row->dueDate->toDateString() }}, {{ $row->doneRatio }}%)">
                                    <div class="h-full rounded bg-indigo-600" style="width: {{ $row->doneRatio }}%"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
