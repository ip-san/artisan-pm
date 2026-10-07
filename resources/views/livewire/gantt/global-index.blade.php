<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\UsesSavedIssueQueriesForFiltering;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Format\DateTimes;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Issues\RelatedIssueColumns;
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
use Illuminate\Support\Facades\Gate;

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
     * Redmine's Helpers::Gantt month_from/year_from/months — see
     * gantt/index.blade.php's own copy of this for the full explanation.
     */
    #[Url]
    public ?int $yearFrom = null;

    #[Url]
    public ?int $monthFrom = null;

    #[Url]
    public ?int $months = null;

    #[Url]
    public bool $drawRelations = true;

    #[Url]
    public bool $drawProgress = false;

    /**
     * Redmine's IssueQuery#draw_selected_columns (A15-11b) — see
     * gantt/index.blade.php's own copy of this for the full explanation.
     */
    #[Url]
    public bool $drawSelectedColumns = false;

    /**
     * See gantt/index.blade.php's own copy of this for the full
     * explanation. Not #[Url]-bound, so it's client-settable;
     * selectedColumnTexts() re-filters it before use.
     *
     * @var array<int, string>
     */
    public array $columns = [];

    protected function queryScopeProject(): ?Project
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function issueListDefaultColumns(): array
    {
        return Setting::get('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']);
    }

    /**
     * @param  array<mixed>  $keys
     * @return array<int, string>
     */
    private function selectableColumns(array $keys): array
    {
        return array_values(array_intersect(array_filter($keys, 'is_string'), array_keys(RelatedIssueColumns::available())));
    }

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
        $ceiling = GanttSettings::monthsLimit() > 0 ? GanttSettings::monthsLimit() : 24;
        $months = ($this->months !== null && $this->months >= 1 && $this->months <= $ceiling) ? $this->months : 6;

        $start = Carbon::create($this->yearFrom, $month, 1);

        return [$start, $start->copy()->addMonthsNoOverflow($months)->subDay()];
    }

    public function mount(): void
    {
        $this->columns = $this->selectableColumns(self::issueListDefaultColumns());

        // Redmine's gantt?query_id=: opens on a saved issue query.
        if (request()->filled('query_id')) {
            $this->loadQuery(request()->integer('query_id'));
        }
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
            ->filter(fn (Project $project) => Gate::allows('viewGantt', $project))
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
        [$periodStart, $periodEnd] = $this->explicitPeriod();

        return new GanttChart($this->rows, $this->versions, GanttSettings::monthsLimit(), $periodStart, $periodEnd);
    }

    /**
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    #[Computed]
    public function relationLines(): array
    {
        return $this->drawRelations ? $this->relationLinesFor(self::ROW_HEIGHT_PX) : [];
    }

    /**
     * A15-11b: draw_selected_columns' text per drawn issue row — see
     * gantt/index.blade.php's own copy of this for the full explanation.
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

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines, $this->selectedColumnTexts);
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
            'zoom' => $this->zoom,
            'lines' => $this->exportLines(),
            'relationSegments' => $this->drawRelations ? GanttChart::relationSegments($this->relationLinesFor(self::PDF_ROW_HEIGHT_PX)) : [],
            'drawProgress' => $this->drawProgress,
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
    public function exportPng(): ?StreamedResponse
    {
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
            ->filter(fn (Project $project) => $projectIds->contains($project->id) || Gate::allows('view', $project));

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
                <button wire:click="exportPdf" class="btn btn-secondary">
                    PDF
                </button>
                <button wire:click="exportPng" class="btn btn-secondary">
                    PNG
                </button>
            </div>
        @endunless
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
        <button type="button" wire:click="zoomOut" @disabled($zoom <= 1) class="btn btn-secondary px-2.5" title="{{ __('縮小') }}">−</button>
        <button type="button" wire:click="zoomIn" @disabled($zoom >= 4) class="btn btn-secondary px-2.5" title="{{ __('拡大') }}">+</button>

        <span class="ml-4">{{ __('表示期間:') }}</span>
        <input type="number" wire:model="yearFrom" placeholder="{{ __('年') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-year-from>
        <input type="number" min="1" max="12" wire:model="monthFrom" placeholder="{{ __('月') }}" class="w-16 rounded-md border-neutral-300 text-sm" data-gantt-month-from>
        <input type="number" min="1" max="{{ \App\Support\Gantt\GanttSettings::monthsLimit() > 0 ? \App\Support\Gantt\GanttSettings::monthsLimit() : 24 }}" wire:model="months" placeholder="{{ __('か月数') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-months>
        <button type="button" wire:click="applyFilters" class="btn btn-secondary px-2.5">{{ __('適用') }}</button>

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

    @if ($this->chart->isEmpty())
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('項目が多いため、先頭:count行だけを表示しています。', ['count' => number_format(GanttSettings::itemsLimit())]) }}</p>
        @endif
        @if ($this->chart->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => GanttSettings::monthsLimit()]) }}</p>
        @endif
        <x-gantt.chart :chart="$this->chart" :lines="$this->lines" :relation-lines="$this->relationLines" :zoom="$zoom" :draw-progress="$drawProgress"
            :draw-selected-columns="$drawSelectedColumns" :selected-column-texts="$this->selectedColumnTexts" />
    @endif
</div>
