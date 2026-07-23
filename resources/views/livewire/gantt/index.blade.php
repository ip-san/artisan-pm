<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\IssueRelationType;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
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
    use InteractsWithQueryFilters;

    public Project $project;

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

    public function applyFilters(): void
    {
        unset($this->rows, $this->rangeStart, $this->rangeEnd, $this->totalDays, $this->monthBands);
    }

    /**
     * This project's own versions with a due date — matches Redmine's
     * milestone markers on the Gantt chart. Deliberately scoped to the
     * project's own versions rather than shared versions reachable from
     * other projects (issues.form's assignableVersions pulls those in for
     * assignment purposes, but a cross-project version's milestone
     * belongs on that other project's own Gantt, not this one's).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->project->versions()->whereNotNull('due_date')->orderBy('due_date')->get();
    }

    #[Computed]
    public function rangeStart(): ?Carbon
    {
        return $this->rows->pluck('startDate')->filter()->min();
    }

    /**
     * The later of the last issue due date and the last version's due
     * date, so a milestone landing after every issue still falls inside
     * the chart's date range instead of being positioned past 100%. Only
     * extended when there's at least one dated issue to begin with — a
     * project with milestones but no dated issues still shows the
     * existing "no issues" empty state rather than a chart with nothing
     * but milestones, a deliberate scope limitation.
     */
    #[Computed]
    public function rangeEnd(): ?Carbon
    {
        $issuesEnd = $this->rows->pluck('dueDate')->filter()->max();

        if ($issuesEnd === null) {
            return null;
        }

        $versionsEnd = $this->versions->pluck('due_date')->filter()->max();

        return collect([$issuesEnd, $versionsEnd])->filter()->max();
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

    public function versionMarkerLeftPercent(Version $version): float
    {
        return $this->percentFromStart($version->due_date ?? throw new LogicException('Version is missing a due date.'));
    }

    /**
     * Every issue row is a fixed 32px tall (Tailwind's h-8) — matches the
     * label/timeline columns' own row markup below.
     */
    private const int ROW_HEIGHT_PX = 32;

    /**
     * Connector lines between related issues currently visible on this
     * chart — matches Redmine's Gantt#relations (only precedes/blocks are
     * drawn, Redmine's own DRAW_TYPES; a line is skipped if either end
     * has no date range, since there's no bar edge to anchor it to).
     *
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string}>
     */
    #[Computed]
    public function relationLines(): array
    {
        $rowsById = $this->rows->keyBy('id');
        $indexById = $this->rows->values()->map(fn (GanttRow $row) => $row->id)->flip();

        $lines = [];

        foreach (app(GanttService::class)->relationsWithin($this->rows->pluck('id')) as $relation) {
            $from = $rowsById->get($relation->issue_from_id);
            $to = $rowsById->get($relation->issue_to_id);

            if ($from === null || $to === null || ! $from->hasDateRange() || ! $to->hasDateRange()) {
                continue;
            }

            $lines[] = [
                'x1' => $this->barLeftPercent($from) + $this->barWidthPercent($from),
                'y1' => ($indexById[$from->id] + 0.5) * self::ROW_HEIGHT_PX,
                'x2' => $this->barLeftPercent($to),
                'y2' => ($indexById[$to->id] + 0.5) * self::ROW_HEIGHT_PX,
                'color' => $relation->relation_type === IssueRelationType::Blocks ? '#fa5252' : '#228be6',
            ];
        }

        return $lines;
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
                    @foreach ($this->versions as $version)
                        <div wire:key="version-label-{{ $version->id }}" class="flex h-8 items-center border-b border-gray-100 px-2 text-sm text-gray-700">
                            <span class="truncate">◆ {{ $version->name }}</span>
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

                    @if ($this->relationLines !== [])
                        <svg class="pointer-events-none absolute left-0" style="top: 32px; width: 100%; height: {{ count($this->rows) * 32 }}px">
                            @foreach ($this->relationLines as $line)
                                <line wire:key="relation-line-{{ $loop->index }}"
                                    x1="{{ $line['x1'] }}%" y1="{{ $line['y1'] }}"
                                    x2="{{ $line['x2'] }}%" y2="{{ $line['y2'] }}"
                                    stroke="{{ $line['color'] }}" stroke-width="1.5" />
                            @endforeach
                        </svg>
                    @endif

                    @foreach ($this->versions as $version)
                        <div wire:key="version-row-{{ $version->id }}" class="relative h-8 border-b border-gray-100">
                            <div class="absolute top-1 flex h-6 -translate-x-1/2 items-center gap-1 text-amber-600"
                                style="left: {{ $this->versionMarkerLeftPercent($version) }}%"
                                title="{{ $version->name }} ({{ $version->due_date->toDateString() }}, {{ round($version->completedPercent()) }}%)">
                                <span class="text-lg leading-none">◆</span>
                                <span class="text-xs text-gray-500">{{ round($version->completedPercent()) }}%</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
