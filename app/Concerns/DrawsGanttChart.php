<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Issue;
use App\Models\Setting;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Format\DateTimes;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttSettings;
use App\Support\Issues\RelatedIssueColumns;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The chart side shared by the project Gantt chart and the cross-project
 * one: Redmine's gantt options (zoom, explicit period, relations,
 * progress line, selected columns), the chart drawn from the component's
 * lines, and the PDF/PNG exports. The component decides which lines are
 * drawn — it provides the computed `lines` (array{kind, depth, project,
 * row?, version?}) and `rows` (the issue rows among them), and uses
 * UsesSavedIssueQueriesForFiltering with its loadQuery() aliased to
 * loadIssueQuery().
 */
trait DrawsGanttChart
{
    /**
     * Every issue row is a fixed 32px tall (Tailwind's h-8) — matches the
     * label/timeline columns' own row markup.
     */
    private const int ROW_HEIGHT_PX = 32;

    /**
     * The PDF export's row height (resources/views/pdf/gantt.blade.php).
     */
    private const int PDF_ROW_HEIGHT_PX = 16;

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
     * to 6 outside that range); see GanttSettings::periodMonthsCeiling().
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

    /**
     * Checks the viewer may export the chart (the mount-time check, again
     * on each export request).
     */
    abstract protected function authorizeGanttExport(): void;

    /**
     * The exports' document title and file name, without extension.
     */
    abstract protected function exportBaseName(): string;

    /**
     * What the PDF export's heading names the chart after.
     */
    abstract protected function exportHeadingSubject(): string;

    /**
     * The site's issue_list_default_columns (the same fallback
     * saveQuery() on the issue list itself, and
     * UsesSavedIssueQueriesForFiltering's saveQuery(), use for a brand-new
     * query).
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
     * The initial column set, then Redmine's gantt?query_id=: opens on a
     * saved issue query.
     */
    private function mountGanttChart(): void
    {
        $this->columns = $this->selectableColumns(self::issueListDefaultColumns());

        if (request()->filled('query_id')) {
            $this->loadQuery(request()->integer('query_id'));
        }
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
        $months = ($this->months !== null && $this->months >= 1 && $this->months <= GanttSettings::periodMonthsCeiling()) ? $this->months : 6;

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

    /**
     * Each drawn milestone's completion, computed for all of them at once.
     *
     * @return array<int, float> keyed by version id
     */
    #[Computed]
    public function versionPercents(): array
    {
        return Version::completedPercents($this->lines->where('kind', 'version')->pluck('version'), auth()->user());
    }

    #[Computed]
    public function chart(): GanttChart
    {
        [$periodStart, $periodEnd] = $this->explicitPeriod();

        return new GanttChart($this->rows, $this->versions, GanttSettings::monthsLimit(), $periodStart, $periodEnd);
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

    /**
     * Connector lines between related issues currently visible on the
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
            'version' => GanttLine::version($line['version'], $line['depth'], $this->versionPercents[$line['version']->id] ?? null),
        })->all();
    }

    /**
     * The HTML the PDF export hands to dompdf.
     */
    public function pdfHtml(): string
    {
        return view('pdf.gantt', [
            'documentTitle' => $this->exportBaseName(),
            'heading' => __(':project - ガントチャート (:date時点)', ['project' => $this->exportHeadingSubject(), 'date' => DateTimes::date(DateTimes::today())]),
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
        $this->authorizeGanttExport();

        if ($this->chart->isEmpty()) {
            abort(404);
        }

        $pdf = Pdf::loadHTML($this->pdfHtml())->setPaper('a4', 'landscape')->output();

        return response()->streamDownload(
            fn () => print ($pdf),
            "{$this->exportBaseName()}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Matches Redmine's GanttsController#show format.png (see
     * GanttImageRenderer).
     */
    public function exportPng(): ?StreamedResponse
    {
        $this->authorizeGanttExport();

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
            "{$this->exportBaseName()}.png",
            ['Content-Type' => 'image/png'],
        );
    }
}
