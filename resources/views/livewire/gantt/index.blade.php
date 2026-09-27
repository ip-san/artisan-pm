<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\UsesSavedIssueQueriesForFiltering;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Issues\RelatedIssueColumns;
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
    use UsesSavedIssueQueriesForFiltering {
        loadQuery as private loadIssueQuery;
    }

    /**
     * Redmine's gantt zoom (1-4, default 2): months, then week numbers, days
     * and weekdays in the header, each level drawing the days wider.
     */
    #[Url]
    public int $zoom = 2;

    /**
     * Redmine's Helpers::Gantt month_from/year_from/months: an explicit
     * period, overriding the default of fitting the chart to the drawn
     * issues. Redmine clamps months to 1..gantt_months_limit (falling back
     * to 6 outside that range); this app's monthsLimit() is 0 (unlimited)
     * by default, so the fallback ceiling is 24 in that case, matching
     * Redmine's own further clamp of a limit-less months value.
     */
    #[Url]
    public ?int $yearFrom = null;

    #[Url]
    public ?int $monthFrom = null;

    #[Url]
    public ?int $months = null;

    /**
     * Redmine's IssueQuery#draw_relations / #draw_progress_line: both
     * persisted per query (options), defaulting to relations shown and
     * the progress overlay hidden.
     */
    #[Url]
    public bool $drawRelations = true;

    #[Url]
    public bool $drawProgress = false;

    /**
     * Redmine's IssueQuery#draw_selected_columns (A15-11b): the chosen
     * columns of a loaded issue query, shown as compact extra text next
     * to each issue row — off by default, like Redmine's own
     * draw_selected_columns (false unless explicitly turned on).
     */
    #[Url]
    public bool $drawSelectedColumns = false;

    /**
     * The columns to show when drawSelectedColumns is on — reuses
     * RelatedIssueColumns (the same compact, visibility-aware inline
     * column set/renderer the issue detail page's related-issues table
     * uses) rather than the full issue list column machinery, since a
     * Gantt row has room for short text only and RelatedIssueColumns
     * already excludes subject/tracker (shown separately here too) and
     * anything too long for a single line (description, relations,
     * attachments, watchers). loadQuery() below fills this from a loaded
     * issue query's column_names, defaulting to the site's
     * issue_list_default_columns like a column-less query does on the
     * issue list itself (Query#columns' has_default_columns? branch).
     * Never written back to a query — read-side only, see loadQuery().
     * Not #[Url]-bound, so it's client-settable; selectedColumnTexts()
     * re-filters it before use rather than trusting it's still one of
     * RelatedIssueColumns' own keys.
     *
     * @var array<int, string>
     */
    public array $columns = [];

    protected function queryScopeProject(): ?Project
    {
        return $this->project;
    }

    /**
     * The site's issue_list_default_columns (the same fallback
     * saveQuery() on the issue list itself, and this trait's own
     * saveQuery(), use for a brand-new query).
     *
     * @return array<int, string>
     */
    private static function issueListDefaultColumns(): array
    {
        return Setting::get('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']);
    }

    /**
     * $keys narrowed to strings RelatedIssueColumns actually knows how to
     * render — the guard both loadQuery() and selectedColumnTexts() apply
     * before doing anything with a column key, since $columns isn't
     * #[Url]-validated and is otherwise client-settable.
     *
     * @param  array<mixed>  $keys
     * @return array<int, string>
     */
    private function selectableColumns(array $keys): array
    {
        return array_values(array_intersect(array_filter($keys, 'is_string'), array_keys(RelatedIssueColumns::available())));
    }

    /**
     * Applies a saved issue query's filters (UsesSavedIssueQueriesForFiltering's
     * own loadQuery()) and, in addition, its column_names as the
     * draw_selected_columns column set — falling back to the site's
     * issue_list_default_columns when the query has none of its own,
     * same as the issue list would for a column-less query.
     */
    public function loadQuery(int $queryId): void
    {
        $this->loadIssueQuery($queryId);

        $query = $this->savedQueries->firstWhere('id', $queryId);
        $columnNames = $query !== null && ($query->column_names ?? []) !== [] ? $query->column_names : self::issueListDefaultColumns();
        $this->columns = $this->selectableColumns($columnNames);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|array{0: null, 1: null}
     */
    private function explicitPeriod(): array
    {
        if ($this->yearFrom === null) {
            return [null, null];
        }

        $month = ($this->monthFrom !== null && $this->monthFrom >= 1 && $this->monthFrom <= 12) ? $this->monthFrom : 1;
        $ceiling = self::monthsLimit() > 0 ? self::monthsLimit() : 24;
        $months = ($this->months !== null && $this->months >= 1 && $this->months <= $ceiling) ? $this->months : 6;

        $start = Carbon::create($this->yearFrom, $month, 1);

        return [$start, $start->copy()->addMonthsNoOverflow($months)->subDay()];
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
        $this->columns = $this->selectableColumns(self::issueListDefaultColumns());

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

    /**
     * A15-11b: draw_selected_columns' text per drawn issue row — only
     * fetched when the toggle is on, since it means loading full Issue
     * models (with the relations RelatedIssueColumns needs) for every
     * drawn row rather than the lean GanttRow projection the chart
     * otherwise draws from. A field the viewer's role can't see in that
     * issue's project renders as blank, matching every other custom
     * field cell in this app (RelatedIssueColumns::visibleCustomFieldIds()
     * defers to Issue::relevantCustomFields(), the same per-role check
     * the issue list and detail page use).
     *
     * @return array<int, string> issue id => joined column text
     */
    #[Computed]
    public function selectedColumnTexts(): array
    {
        // $columns isn't #[Url]-validated (it's only ever set from this
        // component's own loadQuery()/mount(), but is still a public
        // Livewire property and so client-settable) — re-filter it here
        // rather than trust it still holds only RelatedIssueColumns keys.
        $columns = $this->selectableColumns($this->columns);

        if (! $this->drawSelectedColumns || $columns === []) {
            return [];
        }

        $issueIds = $this->rows->pluck('id');

        if ($issueIds->isEmpty()) {
            return [];
        }

        $issues = Issue::query()
            ->whereIn('id', $issueIds)
            ->with(['project', ...RelatedIssueColumns::relationsFor($columns)])
            ->get();

        $visibleCustomFieldIds = RelatedIssueColumns::visibleCustomFieldIds($issues, auth()->user());

        return $issues->mapWithKeys(fn (Issue $issue) => [
            $issue->id => collect($columns)
                ->map(fn (string $key) => RelatedIssueColumns::value($issue, $key, $visibleCustomFieldIds[$issue->id] ?? []))
                ->filter(fn (string $text) => $text !== '')
                ->join(' / '),
        ])->all();
    }

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines, $this->scopeProjects, $this->withSubprojectHeadings, $this->engine, $this->selectedColumnTexts);
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
        [$periodStart, $periodEnd] = $this->explicitPeriod();

        return new GanttChart($this->rows, $this->versions, self::monthsLimit(), $periodStart, $periodEnd);
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
        return $this->drawRelations ? $this->relationLinesFor(self::ROW_HEIGHT_PX) : [];
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
            'relationSegments' => $this->drawRelations ? GanttChart::relationSegments($this->relationLinesFor(self::PDF_ROW_HEIGHT_PX)) : [],
            'drawProgress' => $this->drawProgress,
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
                <button wire:click="exportPdf" class="btn btn-secondary">
                    PDF
                </button>
                <button wire:click="exportPng" class="btn btn-secondary">
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
            <button wire:click="applyFilters" class="btn btn-primary">
                {{ __('絞り込み適用') }}
            </button>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-gantt-saved-queries>
        <span class="text-neutral-500">{{ __('保存済みクエリ:') }}</span>
        @forelse ($this->savedQueries as $savedQuery)
            <x-saved-query-pill :query="$savedQuery" />
        @empty
            <span class="text-neutral-500">{{ __('なし') }}</span>
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
                :roles="$this->availableRoles"
                :editing="$editingQueryId !== null" />
        </div>
    @endif

    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm text-neutral-700" data-gantt-zoom-controls>
        <span>{{ __('ズーム') }}</span>
        <button type="button" wire:click="zoomOut" @disabled($zoom <= 1) class="rounded border border-neutral-300 px-2 py-0.5 hover:bg-neutral-50 disabled:opacity-40" title="{{ __('縮小') }}">−</button>
        <button type="button" wire:click="zoomIn" @disabled($zoom >= 4) class="rounded border border-neutral-300 px-2 py-0.5 hover:bg-neutral-50 disabled:opacity-40" title="{{ __('拡大') }}">+</button>

        <span class="ml-4">{{ __('表示期間:') }}</span>
        <input type="number" wire:model="yearFrom" placeholder="{{ __('年') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-year-from>
        <input type="number" min="1" max="12" wire:model="monthFrom" placeholder="{{ __('月') }}" class="w-16 rounded-md border-neutral-300 text-sm" data-gantt-month-from>
        <input type="number" min="1" max="{{ self::monthsLimit() > 0 ? self::monthsLimit() : 24 }}" wire:model="months" placeholder="{{ __('か月数') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-months>
        <button type="button" wire:click="applyFilters" class="rounded border border-neutral-300 px-2 py-0.5 hover:bg-neutral-50">{{ __('適用') }}</button>

        <label class="ml-4 flex items-center gap-1">
            <input type="checkbox" wire:model.live="drawRelations" class="rounded border-neutral-300" data-gantt-draw-relations>
            {{ __('関連課題の線') }}
        </label>
        <label class="flex items-center gap-1">
            <input type="checkbox" wire:model.live="drawProgress" class="rounded border-neutral-300" data-gantt-draw-progress>
            {{ __('進捗率の表示') }}
        </label>
        <label class="flex items-center gap-1">
            <input type="checkbox" wire:model.live="drawSelectedColumns" class="rounded border-neutral-300" data-gantt-draw-selected-columns>
            {{ __('選択した列を表示') }}
        </label>
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
        <x-gantt.chart :chart="$this->chart" :lines="$this->lines" :relation-lines="$this->relationLines" :zoom="$zoom" :draw-progress="$drawProgress"
            :draw-selected-columns="$drawSelectedColumns" :selected-column-texts="$this->selectedColumnTexts" />
    @endif
</div>
