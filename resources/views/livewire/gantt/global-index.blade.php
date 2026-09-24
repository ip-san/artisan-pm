<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Format\DateTimes;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Redmine's top-level IssuesController#gantt (/issues/gantt, no project):
 * the issues of every project the viewer can view_gantt in, grouped under
 * their project in the project hierarchy (Redmine's Gantt#render walks
 * Project.project_tree). Each project's group is its issue tree followed
 * by its milestones, the same as the project chart.
 *
 * Which issues are drawn goes through Issue::scopeVisibleToAcrossProjects(),
 * so each project's own visibility rules apply (issues_visibility, private
 * issues, per-tracker view_issues, group assignment) exactly as on the
 * cross-project issue list.
 *
 * gantt_items_limit counts every row of the chart — project headers, issues
 * and milestones alike — across all projects, as Redmine's
 * render_object_row does.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

    /**
     * Every issue row is a fixed 32px tall (Tailwind's h-8).
     */
    private const int ROW_HEIGHT_PX = 32;

    /**
     * The PDF export's row height (resources/views/pdf/gantt.blade.php).
     */
    private const int PDF_ROW_HEIGHT_PX = 16;

    /**
     * The projects whose issues are drawn: those the viewer can view_gantt in.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->orderBy('_lft')
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('viewGantt', $project))
            ->values();
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProjects($this->visibleProjects));
    }

    /**
     * Every row of the chart before the items limit, in drawing order.
     *
     * @return Collection<int, array{kind: string, depth: int, project: Project, row?: GanttRow, version?: Version}>
     */
    #[Computed]
    public function allLines(): Collection
    {
        $visibleIssues = Issue::query()->visibleToAcrossProjects(auth()->user(), $this->visibleProjects);
        $filters = $this->builtFilters();
        $matchedIds = $filters === []
            ? null
            : $this->engine->applyFilters($visibleIssues->clone(), $filters)->pluck('issues.id');

        $service = app(GanttService::class);
        $trees = $service->issueTreesByProject($visibleIssues, $matchedIds)->filter(fn (Collection $tree) => $tree->isNotEmpty());

        $lines = collect();

        foreach ($this->projectTree($trees->keys()) as [$project, $depth]) {
            $lines->push(['kind' => 'project', 'depth' => $depth, 'project' => $project]);
            $tree = $trees->get($project->id, collect());

            foreach ($tree as $row) {
                $lines->push(['kind' => 'issue', 'depth' => $depth + 1 + $row->depth, 'project' => $project, 'row' => $row]);
            }

            if ($tree->isNotEmpty()) {
                foreach ($service->milestones($project, $tree) as $version) {
                    $lines->push(['kind' => 'version', 'depth' => $depth + 1, 'project' => $project, 'version' => $version]);
                }
            }
        }

        return $lines;
    }

    /**
     * The rows actually drawn: allLines() cut to the items limit.
     *
     * @return Collection<int, array{kind: string, depth: int, project: Project, row?: GanttRow, version?: Version}>
     */
    #[Computed]
    public function lines(): Collection
    {
        $limit = GanttSettings::itemsLimit();

        return $limit > 0 ? $this->allLines->take($limit)->values() : $this->allLines;
    }

    #[Computed]
    public function rowsTruncated(): bool
    {
        return $this->allLines->count() > $this->lines->count();
    }

    /**
     * The issue rows drawn.
     *
     * @return Collection<int, GanttRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->lines->where('kind', 'issue')->pluck('row')->values();
    }

    /**
     * The milestones drawn.
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->lines->where('kind', 'version')->pluck('version')->values();
    }

    #[Computed]
    public function chart(): GanttChart
    {
        return new GanttChart($this->rows, $this->versions, GanttSettings::monthsLimit());
    }

    /**
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    #[Computed]
    public function relationLines(): array
    {
        return $this->relationLinesFor(self::ROW_HEIGHT_PX);
    }

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines);
    }

    /**
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    private function relationLinesFor(int $rowHeight): array
    {
        $indexById = [];

        foreach ($this->lines as $index => $line) {
            if ($line['kind'] === 'issue') {
                $indexById[$line['row']->id] = $index;
            }
        }

        return $this->chart->relationLines(
            app(GanttService::class)->relationsWithin($this->rows->pluck('id')),
            $this->rows,
            $indexById,
            $rowHeight,
        );
    }

    /**
     * The chart's rows for the PDF/PNG exports.
     *
     * @return array<int, GanttLine>
     */
    public function exportLines(): array
    {
        return $this->lines->map(fn (array $line) => match ($line['kind']) {
            'project' => GanttLine::project($line['project'], $line['depth']),
            'issue' => GanttLine::issue($line['row'], $line['depth']),
            'version' => GanttLine::version($line['version'], $line['depth']),
        })->all();
    }

    /**
     * The HTML the PDF export hands to dompdf.
     */
    public function pdfHtml(): string
    {
        return view('pdf.gantt', [
            'documentTitle' => 'gantt',
            'heading' => __(':project - ガントチャート (:date時点)', ['project' => __('全プロジェクト'), 'date' => DateTimes::date(DateTimes::today())]),
            'chart' => $this->chart,
            'lines' => $this->exportLines(),
            'relationSegments' => GanttChart::relationSegments($this->relationLinesFor(self::PDF_ROW_HEIGHT_PX)),
        ])->render();
    }

    /**
     * Redmine's /issues/gantt.pdf — the same view as the project chart's PDF.
     */
    public function exportPdf(): StreamedResponse
    {
        if ($this->chart->isEmpty()) {
            abort(404);
        }

        $html = $this->pdfHtml();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();

        return response()->streamDownload(fn () => print ($pdf), 'gantt.pdf', ['Content-Type' => 'application/pdf']);
    }

    /**
     * Redmine's /issues/gantt.png (see GanttImageRenderer).
     */
    public function exportPng(): StreamedResponse
    {
        if ($this->chart->isEmpty()) {
            abort(404);
        }

        $png = app(GanttImageRenderer::class)->render($this->chart, $this->exportLines());

        return response()->streamDownload(fn () => print ($png), 'gantt.png', ['Content-Type' => 'image/png']);
    }

    /**
     * The projects with drawn issues and their ancestors the viewer can see,
     * in hierarchy order with their depth among the listed projects —
     * Redmine's Gantt#projects + Project.project_tree.
     *
     * @param  Collection<int, int>  $projectIds
     * @return array<int, array{0: Project, 1: int}>
     */
    private function projectTree(Collection $projectIds): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        $withIssues = Project::query()->whereIn('id', $projectIds)->get(['id', '_lft', '_rgt']);
        $listed = Project::query()
            ->where(function ($query) use ($withIssues): void {
                foreach ($withIssues as $project) {
                    $query->orWhere(fn ($ancestorOrSelf) => $ancestorOrSelf
                        ->where('_lft', '<=', $project->_lft)
                        ->where('_rgt', '>=', $project->_rgt));
                }
            })
            ->orderBy('_lft')
            ->get()
            ->filter(fn (Project $project) => $projectIds->contains($project->id) || auth()->user()?->can('view', $project));

        $tree = [];
        $openRights = [];

        foreach ($listed as $project) {
            while ($openRights !== [] && end($openRights) < $project->_lft) {
                array_pop($openRights);
            }

            $tree[] = [$project, count($openRights)];
            $openRights[] = $project->_rgt;
        }

        return $tree;
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('ガントチャート(全プロジェクト)') }}</h1>
        @unless ($this->chart->isEmpty())
            <div class="flex gap-2">
                <button wire:click="exportPdf" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    PDF
                </button>
                <button wire:click="exportPng" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    PNG
                </button>
            </div>
        @endunless
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('絞り込み適用') }}
            </button>
        </div>
    </div>

    @if ($this->chart->isEmpty())
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('項目が多いため、先頭:count行だけを表示しています。', ['count' => number_format(GanttSettings::itemsLimit())]) }}</p>
        @endif
        @if ($this->chart->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => GanttSettings::monthsLimit()]) }}</p>
        @endif
        <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
            <div class="flex min-w-[900px]">
                <div class="w-80 shrink-0 border-r border-neutral-200">
                    <div class="h-8 border-b border-neutral-200 bg-neutral-50"></div>
                    @foreach ($this->lines as $line)
                        <div wire:key="label-{{ $line['kind'] }}-{{ $line['project']->id }}-{{ $line['row']->id ?? $line['version']->id ?? 0 }}"
                            class="flex h-8 items-center border-b border-neutral-100 px-2 text-sm"
                            style="padding-left: {{ 8 + $line['depth'] * 16 }}px">
                            @if ($line['kind'] === 'project')
                                <a href="{{ route('projects.show', $line['project']) }}" class="truncate font-semibold text-neutral-900 hover:underline" data-gantt-project="{{ $line['project']->id }}">
                                    {{ $line['project']->name }}
                                </a>
                            @elseif ($line['kind'] === 'issue')
                                <a href="{{ route('issues.show', [$line['project'], $line['row']->id]) }}" class="truncate text-brand-bold hover:underline">
                                    {{ $line['row']->trackerName }} #{{ $line['row']->id }}: {{ $line['row']->subject }}
                                </a>
                            @else
                                <span class="truncate text-neutral-700">◆ {{ $line['version']->name }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="relative flex-1">
                    <div class="relative h-8 border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500">
                        @foreach ($this->chart->monthBands() as $band)
                            <div class="absolute top-0 flex h-8 items-center border-l border-neutral-200 pl-1"
                                style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%">
                                {{ $band['label'] }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($this->lines as $line)
                        <div wire:key="timeline-{{ $line['kind'] }}-{{ $line['project']->id }}-{{ $line['row']->id ?? $line['version']->id ?? 0 }}"
                            class="relative h-8 border-b border-neutral-100 {{ $line['kind'] === 'project' ? 'bg-neutral-50' : '' }}">
                            @if ($line['kind'] === 'issue' && $line['row']->hasDateRange())
                                @php $row = $line['row']; @endphp
                                <div class="absolute top-1.5 h-5 rounded {{ $row->isClosed ? 'bg-neutral-400' : 'bg-brand' }}"
                                    style="left: {{ $this->chart->barLeftPercent($row) }}%; width: {{ $this->chart->barWidthPercent($row) }}%"
                                    title="{{ $row->subject }} ({{ \App\Support\Format\DateTimes::date($row->startDate) }} 〜 {{ \App\Support\Format\DateTimes::date($row->dueDate) }}, {{ $row->doneRatio }}%)">
                                    <div class="h-full rounded bg-brand-bold" style="width: {{ $row->doneRatio }}%"></div>
                                </div>
                            @elseif ($line['kind'] === 'version')
                                @php $version = $line['version']; $percent = round($version->asSeenBy(auth()->user())->completedPercent()); @endphp
                                <div class="absolute top-1 flex h-6 -translate-x-1/2 items-center gap-1 text-warning"
                                    style="left: {{ $this->chart->versionMarkerLeftPercent($version) }}%"
                                    title="{{ $version->name }} ({{ \App\Support\Format\DateTimes::date($version->due_date) }}, {{ $percent }}%)">
                                    <span class="text-lg leading-none">◆</span>
                                    <span class="text-xs text-neutral-500">{{ $percent }}%</span>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($this->relationLines !== [])
                        <svg class="pointer-events-none absolute left-0" style="top: 32px; width: 100%; height: {{ count($this->lines) * 32 }}px">
                            @foreach ($this->relationLines as $line)
                                <line wire:key="relation-line-{{ $loop->index }}"
                                    x1="{{ $line['x1'] }}%" y1="{{ $line['y1'] }}"
                                    x2="{{ $line['x2'] }}%" y2="{{ $line['y2'] }}"
                                    stroke="{{ $line['color'] }}" stroke-width="1.5" />
                            @endforeach
                        </svg>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
