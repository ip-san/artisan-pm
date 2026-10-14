<?php

use App\Concerns\DrawsGanttChart;
use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\UsesSavedIssueQueriesForFiltering;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Gantt\GanttProjectTree;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use DrawsGanttChart, InteractsWithQueryFilters, UsesSavedIssueQueriesForFiltering {
        DrawsGanttChart::loadQuery insteadof UsesSavedIssueQueriesForFiltering;
        UsesSavedIssueQueriesForFiltering::loadQuery as private loadIssueQuery;
    }

    public Project $project;

    protected function queryScopeProject(): ?Project
    {
        return $this->project;
    }

    protected function authorizeGanttExport(): void
    {
        $this->authorize('viewGantt', $this->project);
    }

    protected function exportBaseName(): string
    {
        return "{$this->project->identifier}-gantt";
    }

    protected function exportHeadingSubject(): string
    {
        return $this->project->name;
    }

    public function mount(Project $project): void
    {
        $this->authorize('viewGantt', $project);

        $this->project = $project;
        $this->mountGanttChart();
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project, scopeProjects: $this->scopeProjects));
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
        $milestones = $service->milestonesByProject($trees);
        $lines = collect();

        foreach ($this->headedProjects($trees->keys()) as [$project, $depth]) {
            $lines->push(['kind' => 'project', 'depth' => $depth, 'project' => $project]);
            $tree = $trees->get($project->id, collect());

            foreach ($tree as $row) {
                $lines->push(['kind' => 'issue', 'depth' => $depth + 1 + $row->depth, 'project' => $project, 'row' => $row]);
            }

            foreach ($milestones->get($project->id, collect()) as $version) {
                $lines->push(['kind' => 'version', 'depth' => $depth + 1, 'project' => $project, 'version' => $version]);
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

        return GanttProjectTree::withDepths($this->scopeProjects->filter(fn (Project $candidate) => $withIssues->contains(
            fn (Project $project) => $candidate->_lft <= $project->_lft && $candidate->_rgt >= $project->_rgt,
        )));
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
        $limit = GanttSettings::itemsLimit();
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
        $limit = GanttSettings::itemsLimit();

        return $limit > 0 && $this->allLines->count() > $limit;
    }

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines, $this->scopeProjects, $this->withSubprojectHeadings, $this->engine, $this->selectedColumnTexts);
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
        <button type="button" wire:click="zoomOut" @disabled($zoom <= 1) class="btn btn-secondary px-2.5" title="{{ __('縮小') }}">−</button>
        <button type="button" wire:click="zoomIn" @disabled($zoom >= 4) class="btn btn-secondary px-2.5" title="{{ __('拡大') }}">+</button>

        <span class="ml-4">{{ __('表示期間:') }}</span>
        <input type="number" wire:model="yearFrom" placeholder="{{ __('年') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-year-from>
        <input type="number" min="1" max="12" wire:model="monthFrom" placeholder="{{ __('月') }}" class="w-16 rounded-md border-neutral-300 text-sm" data-gantt-month-from>
        <input type="number" min="1" max="{{ GanttSettings::periodMonthsCeiling() }}" wire:model="months" placeholder="{{ __('か月数') }}" class="w-20 rounded-md border-neutral-300 text-sm" data-gantt-months>
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

    @if ($this->rangeStart === null)
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ $this->withSubprojectHeadings ? __('項目が多いため、先頭:count行だけを表示しています。', ['count' => number_format(GanttSettings::itemsLimit())]) : __('課題が多いため、先頭:count件だけを表示しています。', ['count' => number_format(GanttSettings::itemsLimit())]) }}</p>
        @endif
        @if ($this->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => GanttSettings::monthsLimit()]) }}</p>
        @endif
        <x-gantt.chart :chart="$this->chart" :lines="$this->lines" :relation-lines="$this->relationLines" :zoom="$zoom" :draw-progress="$drawProgress" :version-percents="$this->versionPercents"
            :draw-selected-columns="$drawSelectedColumns" :selected-column-texts="$this->selectedColumnTexts" />
    @endif
</div>
