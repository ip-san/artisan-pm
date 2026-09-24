<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\UsesSavedIssueQueriesOnGantt;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Issues\SubprojectScope;
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
    use UsesSavedIssueQueriesOnGantt;

    /**
     * Redmine's gantt zoom (1-4, default 2): months, then week numbers, days
     * and weekdays in the header, each level drawing the days wider.
     */
    #[Url]
    public int $zoom = 2;

    protected function ganttProject(): ?Project
    {
        return $this->project;
    }

    public function zoomIn(): void
    {
        $this->zoom = min(4, max(1, $this->zoom) + 1);
    }

    public function zoomOut(): void
    {
        $this->zoom = max(1, min(4, $this->zoom) - 1);
    }

    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewGantt', $project);

        $this->project = $project;

        // Redmine's gantt?query_id=: opens on a saved issue query.
        if (request()->filled('query_id')) {
            $this->loadQuery(request()->integer('query_id'));
        }
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project, scopeProjects: $this->scopeProjects));
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
     * Every row of the chart before the items limit, in drawing order.
     * With filters active, the tree is restricted to matching issues
     * (their ancestors stay for depth coherence — see GanttService),
     * resolved through the same QueryFilterEngine the issue list uses.
     *
     * The project's own chart is its issue tree. With subprojects in
     * scope it is drawn like Redmine's Gantt#render: a heading row per
     * project in the project tree, each followed by its issues (a subtask
     * whose parent is in another project starts its own project's tree)
     * and its milestones — the cross-project chart's layout.
     *
     * @return Collection<int, array{kind: string, depth: int, project: Project, row?: GanttRow, version?: Version}>
     */
    #[Computed]
    public function allLines(): Collection
    {
        $filters = $this->builtFilters();

        $visibleIssues = Issue::query()->visibleToAcrossProjects(auth()->user(), $this->scopeProjects);
        $matchedIds = $filters === []
            ? null
            : $this->engine->applyFilters($visibleIssues->clone(), $filters)->pluck('issues.id');

        $service = app(GanttService::class);

        if (! $this->withSubprojectHeadings) {
            return $service->issueTree($this->scopeProjects, $matchedIds, $visibleIssues->pluck('issues.id'))
                ->map(fn (GanttRow $row) => ['kind' => 'issue', 'depth' => $row->depth, 'project' => $this->project, 'row' => $row]);
        }

        $trees = $service->issueTreesByProject($visibleIssues, $matchedIds)->filter(fn (Collection $tree) => $tree->isNotEmpty());
        $lines = collect();

        foreach ($this->headedProjects($trees->keys()) as [$project, $depth]) {
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
     * Whether the chart takes in subprojects and so gets project heading
     * rows.
     */
    #[Computed]
    public function withSubprojectHeadings(): bool
    {
        return $this->scopeProjects->count() > 1;
    }

    /**
     * The projects in scope that get a heading — those with drawn issues
     * and their ancestors up to this project — with their depth under it.
     *
     * @param  Collection<int, int>  $projectIdsWithIssues
     * @return array<int, array{0: Project, 1: int}>
     */
    private function headedProjects(Collection $projectIdsWithIssues): array
    {
        $withIssues = $this->scopeProjects->whereIn('id', $projectIdsWithIssues->all());
        $headed = $this->scopeProjects->filter(fn (Project $candidate) => $withIssues->contains(
            fn (Project $project) => $candidate->_lft <= $project->_lft && $candidate->_rgt >= $project->_rgt,
        ));

        $tree = [];
        $openRights = [];

        foreach ($headed as $project) {
            while ($openRights !== [] && end($openRights) < $project->_lft) {
                array_pop($openRights);
            }

            $tree[] = [$project, count($openRights)];
            $openRights[] = $project->_rgt;
        }

        return $tree;
    }

    /**
     * The project plus, with display_subprojects_issues on, the subprojects
     * whose issues the chart also draws (Redmine's gantt goes through the
     * issue query's project_statement).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function scopeProjects(): Collection
    {
        return SubprojectScope::projectsForIssues($this->project, auth()->user(), $this->builtFilters());
    }

    /**
     * The rows actually drawn: allLines() cut to the items limit. On the
     * project's own chart the limit counts issues and the milestones
     * follow; with subproject headings it counts every row, as on the
     * cross-project chart (Redmine's render_object_row).
     *
     * @return Collection<int, array{kind: string, depth: int, project: Project, row?: GanttRow, version?: Version}>
     */
    #[Computed]
    public function lines(): Collection
    {
        $limit = self::itemsLimit();
        $lines = $limit > 0 ? $this->allLines->take($limit)->values() : $this->allLines;

        if ($this->withSubprojectHeadings) {
            return $lines;
        }

        return $lines->concat(app(GanttService::class)->milestones($this->scopeProjects, $lines->pluck('row'))
            ->map(fn (Version $version) => ['kind' => 'version', 'depth' => 0, 'project' => $this->project, 'version' => $version]));
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

    #[Computed]
    public function rowsTruncated(): bool
    {
        $limit = self::itemsLimit();

        return $limit > 0 && $this->allLines->count() > $limit;
    }

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines, $this->scopeProjects, $this->withSubprojectHeadings, $this->engine);
    }

    /**
     * The milestones drawn: the project's own dated versions and dated
     * shared versions a drawn issue targets (GanttService::milestones()),
     * per project with subproject headings.
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
     * The PDF export's row height (resources/views/pdf/gantt.blade.php).
     */
    private const int PDF_ROW_HEIGHT_PX = 16;

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
            'documentTitle' => "{$this->project->identifier}-gantt",
            'heading' => __(':project - ガントチャート (:date時点)', ['project' => $this->project->name, 'date' => \App\Support\Format\DateTimes::date(\App\Support\Format\DateTimes::today())]),
            'chart' => $this->chart,
            'zoom' => $this->zoom,
            'lines' => $this->exportLines(),
            'relationSegments' => GanttChart::relationSegments($this->relationLinesFor(self::PDF_ROW_HEIGHT_PX)),
        ])->render();
    }

    /**
     * Matches Redmine's GanttsController#show format.pdf. Same
     * dompdf-over-a-print-styled-Blade-view approach as the issue/wiki PDF
     * exports; see resources/views/components/pdf/cjk-font.blade.php for why a
     * bundled CJK font is required.
     */
    public function exportPdf(): StreamedResponse
    {
        $this->authorize('viewGantt', $this->project);

        if ($this->chart->isEmpty()) {
            abort(404);
        }

        $html = $this->pdfHtml();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();

        return response()->streamDownload(
            fn () => print ($pdf),
            "{$this->project->identifier}-gantt.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Matches Redmine's GanttsController#show format.png (see
     * GanttImageRenderer).
     */
    public function exportPng(): ?StreamedResponse
    {
        $this->authorize('viewGantt', $this->project);

        if ($this->chart->isEmpty()) {
            abort(404);
        }

        $lines = $this->exportLines();

        if (! GanttImageRenderer::fits($this->chart->totalDays(), count($lines), $this->zoom)) {
            $this->addError('png', __('ガントチャートが大きすぎるため画像にできません(:rows 行 × :months か月)。期間を短くするか、絞り込みで課題を減らしてください。', [
                'rows' => count($lines),
                'months' => count($this->chart->monthBands()),
            ]));

            return null;
        }

        $png = app(GanttImageRenderer::class)->render($this->chart, $lines, $this->zoom);

        return response()->streamDownload(
            fn () => print ($png),
            "{$this->project->identifier}-gantt.png",
            ['Content-Type' => 'image/png'],
        );
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __(':project — ガントチャート', ['project' => $project->name]) }}</h1>
        @if ($this->rangeStart !== null)
            <div class="flex gap-2">
                <button wire:click="exportPdf" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    PDF
                </button>
                <button wire:click="exportPng" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    PNG
                </button>
            </div>
        @endif
    </div>

    @error('png')
        <p class="mb-4 rounded-md border border-danger-subtler bg-danger-subtlest px-4 py-2 text-sm text-danger-bolder" role="alert">{{ $message }}</p>
    @enderror

    <div class="mb-4 rounded-md border border-neutral-200 bg-surface p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('絞り込み適用') }}
            </button>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-gantt-saved-queries>
        <span class="text-neutral-500">{{ __('保存済みクエリ:') }}</span>
        @forelse ($this->savedQueries as $savedQuery)
            <button wire:key="saved-query-{{ $savedQuery->id }}" wire:click="loadQuery({{ $savedQuery->id }})" class="rounded-full border border-neutral-300 px-3 py-1 text-neutral-700 hover:bg-neutral-50">
                {{ $savedQuery->name }}
            </button>
        @empty
            <span class="text-neutral-400">{{ __('なし') }}</span>
        @endforelse
        @if ($this->canSaveQueries)
            <button wire:click="$toggle('showSaveForm')" class="ml-2 text-sm text-brand-bold hover:underline">{{ __('クエリを保存') }}</button>
        @endif
    </div>
    @if ($showSaveForm)
        <div class="mb-4">
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles" />
        </div>
    @endif

    <div class="mb-2 flex items-center gap-2 text-sm text-neutral-700" data-gantt-zoom-controls>
        <span>{{ __('ズーム') }}</span>
        <button type="button" wire:click="zoomOut" @disabled($zoom <= 1) class="rounded border border-neutral-300 px-2 py-0.5 hover:bg-neutral-50 disabled:opacity-40" title="{{ __('縮小') }}">−</button>
        <button type="button" wire:click="zoomIn" @disabled($zoom >= 4) class="rounded border border-neutral-300 px-2 py-0.5 hover:bg-neutral-50 disabled:opacity-40" title="{{ __('拡大') }}">+</button>
    </div>

    @if ($this->rangeStart === null)
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ $this->withSubprojectHeadings ? __('項目が多いため、先頭:count行だけを表示しています。', ['count' => number_format(self::itemsLimit())]) : __('課題が多いため、先頭:count件だけを表示しています。', ['count' => number_format(self::itemsLimit())]) }}</p>
        @endif
        @if ($this->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => self::monthsLimit()]) }}</p>
        @endif
        <x-gantt.chart :chart="$this->chart" :lines="$this->lines" :relation-lines="$this->relationLines" :zoom="$zoom" />
    @endif
</div>
