<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Redmine's gantt_items_limit: at most this many rows are drawn
     * (0 = every row).
     */
    public static function itemsLimit(): int
    {
        return GanttSettings::itemsLimit();
    }

    /**
     * Redmine's gantt_months_limit: the chart spans at most this many months
     * from its first start date (0 = as long as the issues need).
     */
    public static function monthsLimit(): int
    {
        return GanttSettings::monthsLimit();
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
    public function allRows(): Collection
    {
        $filters = $this->builtFilters();

        $visibleIssues = Issue::query()->where('project_id', $this->project->id)->visibleTo(auth()->user(), $this->project);
        $matchedIds = $filters === []
            ? null
            : $this->engine->applyFilters($visibleIssues->clone(), $filters)->pluck('id');

        return app(GanttService::class)->issueTree($this->project, $matchedIds, $visibleIssues->pluck('id'));
    }

    /**
     * The rows actually drawn: allRows() cut to the items limit.
     *
     * @return Collection<int, GanttRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        $limit = self::itemsLimit();

        return $limit > 0 ? $this->allRows->take($limit)->values() : $this->allRows;
    }

    #[Computed]
    public function rowsTruncated(): bool
    {
        return $this->allRows->count() > $this->rows->count();
    }

    public function applyFilters(): void
    {
        unset($this->allRows, $this->rows, $this->versions, $this->chart, $this->relationLines);
    }

    /**
     * The milestones on this chart: the project's own dated versions and
     * dated shared versions a drawn issue targets (GanttService::milestones()).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return app(GanttService::class)->milestones($this->project, $this->rows);
    }

    #[Computed]
    public function chart(): GanttChart
    {
        return new GanttChart($this->rows, $this->versions, self::monthsLimit());
    }

    #[Computed]
    public function rangeStart(): ?Carbon
    {
        return $this->chart->rangeStart;
    }

    /**
     * The later of the last issue due date and the last milestone, cut to
     * the months limit (see GanttChart).
     */
    #[Computed]
    public function rangeEnd(): ?Carbon
    {
        return $this->chart->rangeEnd;
    }

    /**
     * Whether the months limit cut the chart short of what the issues span.
     */
    #[Computed]
    public function monthsTruncated(): bool
    {
        return $this->chart->monthsTruncated;
    }

    #[Computed]
    public function totalDays(): int
    {
        return $this->chart->totalDays();
    }

    /**
     * @return array<int, array{label: string, leftPercent: float, widthPercent: float, start: Carbon, end: Carbon}>
     */
    #[Computed]
    public function monthBands(): array
    {
        return $this->chart->monthBands();
    }

    public function barLeftPercent(GanttRow $row): float
    {
        return $this->chart->barLeftPercent($row);
    }

    public function barWidthPercent(GanttRow $row): float
    {
        return $this->chart->barWidthPercent($row);
    }

    public function versionMarkerLeftPercent(Version $version): float
    {
        return $this->chart->versionMarkerLeftPercent($version);
    }

    /**
     * Every issue row is a fixed 32px tall (Tailwind's h-8) — matches the
     * label/timeline columns' own row markup below.
     */
    private const int ROW_HEIGHT_PX = 32;

    /**
     * Connector lines between related issues currently visible on this
     * chart (GanttChart::relationLines()).
     *
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    #[Computed]
    public function relationLines(): array
    {
        return $this->relationLinesFor(self::ROW_HEIGHT_PX);
    }

    /**
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    private function relationLinesFor(int $rowHeight): array
    {
        return $this->chart->relationLines(
            app(GanttService::class)->relationsWithin($this->rows->pluck('id')),
            $this->rows,
            $this->rows->values()->map(fn (GanttRow $row) => $row->id)->flip()->all(),
            $rowHeight,
        );
    }

    /**
     * Matches Redmine's GanttsController#show format.pdf. Same
     * dompdf-over-a-print-styled-Blade-view approach as the issue/wiki PDF
     * exports; see resources/views/components/pdf/cjk-font.blade.php for why a
     * bundled CJK font is required. The relation-line overlay the HTML
     * chart draws via inline SVG is intentionally left out here — dompdf's
     * SVG support is inconsistent enough that verifying it renders
     * correctly (the same empirical bar the CJK font work was held to)
     * wasn't practical within this slice; bars, month bands, and
     * milestones (the chart's primary information) are unaffected.
     */
    public function exportPdf(): StreamedResponse
    {
        $this->authorize('viewGantt', $this->project);

        if ($this->rangeStart === null) {
            abort(404);
        }

        $barPositions = $this->rows->mapWithKeys(fn (GanttRow $row) => [
            $row->id => $row->hasDateRange()
                ? ['left' => $this->barLeftPercent($row), 'width' => $this->barWidthPercent($row)]
                : null,
        ])->filter();

        $versionPositions = $this->versions->mapWithKeys(fn (Version $version) => [
            $version->id => $this->versionMarkerLeftPercent($version),
        ]);

        $html = view('pdf.gantt', [
            'project' => $this->project,
            'rows' => $this->rows,
            'versions' => $this->versions,
            'monthBands' => $this->monthBands,
            'barPositions' => $barPositions,
            'versionPositions' => $versionPositions,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();

        return response()->streamDownload(
            fn () => print ($pdf),
            "{$this->project->identifier}-gantt.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __(':project — ガントチャート', ['project' => $project->name]) }}</h1>
        @if ($this->rangeStart !== null)
            <button wire:click="exportPdf" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                PDF
            </button>
        @endif
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('絞り込み適用') }}
            </button>
        </div>
    </div>

    @if ($this->rangeStart === null)
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('課題が多いため、先頭:count件だけを表示しています。', ['count' => number_format(self::itemsLimit())]) }}</p>
        @endif
        @if ($this->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => self::monthsLimit()]) }}</p>
        @endif
        <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
            <div class="flex min-w-[900px]">
                <div class="w-80 shrink-0 border-r border-neutral-200">
                    <div class="h-8 border-b border-neutral-200 bg-neutral-50"></div>
                    @foreach ($this->rows as $row)
                        <div wire:key="label-{{ $row->id }}" class="flex h-8 items-center border-b border-neutral-100 px-2 text-sm"
                            style="padding-left: {{ 8 + $row->depth * 16 }}px">
                            <a href="{{ route('issues.show', [$project, $row->id]) }}" class="truncate text-brand-bold hover:underline">
                                {{ $row->trackerName }} #{{ $row->id }}: {{ $row->subject }}
                            </a>
                        </div>
                    @endforeach
                    @foreach ($this->versions as $version)
                        <div wire:key="version-label-{{ $version->id }}" class="flex h-8 items-center border-b border-neutral-100 px-2 text-sm text-neutral-700">
                            <span class="truncate">◆ {{ $version->name }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="relative flex-1">
                    <div class="relative h-8 border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500">
                        @foreach ($this->monthBands as $band)
                            <div class="absolute top-0 flex h-8 items-center border-l border-neutral-200 pl-1"
                                style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%">
                                {{ $band['label'] }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($this->rows as $row)
                        <div wire:key="row-{{ $row->id }}" class="relative h-8 border-b border-neutral-100">
                            @if ($row->hasDateRange())
                                <div class="absolute top-1.5 h-5 rounded {{ $row->isClosed ? 'bg-neutral-400' : 'bg-brand' }}"
                                    style="left: {{ $this->barLeftPercent($row) }}%; width: {{ $this->barWidthPercent($row) }}%"
                                    title="{{ $row->subject }} ({{ \App\Support\Format\DateTimes::date($row->startDate) }} 〜 {{ \App\Support\Format\DateTimes::date($row->dueDate) }}, {{ $row->doneRatio }}%)">
                                    <div class="h-full rounded bg-brand-bold" style="width: {{ $row->doneRatio }}%"></div>
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
                        <div wire:key="version-row-{{ $version->id }}" class="relative h-8 border-b border-neutral-100">
                            <div class="absolute top-1 flex h-6 -translate-x-1/2 items-center gap-1 text-warning"
                                style="left: {{ $this->versionMarkerLeftPercent($version) }}%"
                                title="{{ $version->name }} ({{ \App\Support\Format\DateTimes::date($version->due_date) }}, {{ round($version->asSeenBy(auth()->user())->completedPercent()) }}%)">
                                <span class="text-lg leading-none">◆</span>
                                <span class="text-xs text-neutral-500">{{ round($version->asSeenBy(auth()->user())->completedPercent()) }}%</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
