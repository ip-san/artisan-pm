<?php

use App\Concerns\DrawsGanttChart;
use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\UsesSavedIssueQueriesForFiltering;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Services\GanttService;
use App\Support\Authorization\AuthorizationService;
use App\Support\Gantt\GanttProjectTree;
use App\Support\Gantt\GanttRow;
use App\Support\Gantt\GanttSettings;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

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
    use DrawsGanttChart, InteractsWithQueryFilters, UsesSavedIssueQueriesForFiltering {
        DrawsGanttChart::loadQuery insteadof UsesSavedIssueQueriesForFiltering;
        UsesSavedIssueQueriesForFiltering::loadQuery as private loadIssueQuery;
    }

    protected function queryScopeProject(): ?Project
    {
        return null;
    }

    /**
     * Each project's own view_gantt already narrowed what is drawn.
     */
    protected function authorizeGanttExport(): void {}

    protected function exportBaseName(): string
    {
        return 'gantt';
    }

    protected function exportHeadingSubject(): string
    {
        return __('全プロジェクト');
    }

    public function mount(): void
    {
        $this->mountGanttChart();
    }

    /**
     * The projects whose issues are drawn: those the viewer can view_gantt in.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        // Modules eager-loaded and roles prefetched: the per-project Gate check then runs no queries.
        $projects = Project::query()->with('moduleAssignments')->orderBy('_lft')->get();
        app(AuthorizationService::class)->prefetchRoles(auth()->user(), $projects);

        return $projects
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

        $milestones = $service->milestonesByProject($trees);
        $lines = collect();

        foreach ($this->projectTree($trees->keys()) as [$project, $depth]) {
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

    public function applyFilters(): void
    {
        unset($this->allLines, $this->lines, $this->rows, $this->versions, $this->chart, $this->relationLines, $this->selectedColumnTexts);
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

        return GanttProjectTree::withDepths(Project::query()
            ->with('moduleAssignments')
            ->where(function ($query) use ($withIssues): void {
                foreach ($withIssues as $project) {
                    $query->orWhere(fn ($ancestorOrSelf) => $ancestorOrSelf
                        ->where('_lft', '<=', $project->_lft)
                        ->where('_rgt', '>=', $project->_rgt));
                }
            })
            ->orderBy('_lft')
            ->get()
            ->filter(fn (Project $project) => $projectIds->contains($project->id) || Gate::allows('view', $project)));
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

    @if ($this->chart->isEmpty())
        <p class="text-sm text-neutral-500">{{ __('開始日・期日が設定された課題がありません。') }}</p>
    @else
        @if ($this->rowsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('項目が多いため、先頭:count行だけを表示しています。', ['count' => number_format(GanttSettings::itemsLimit())]) }}</p>
        @endif
        @if ($this->chart->monthsTruncated)
            <p class="mb-2 text-sm text-warning-bold">{{ __('期間が長いため、開始から:monthsか月分だけを表示しています。', ['months' => GanttSettings::monthsLimit()]) }}</p>
        @endif
        <x-gantt.chart :chart="$this->chart" :lines="$this->lines" :relation-lines="$this->relationLines" :zoom="$zoom" :draw-progress="$drawProgress" :version-percents="$this->versionPercents"
            :draw-selected-columns="$drawSelectedColumns" :selected-column-texts="$this->selectedColumnTexts" />
    @endif
</div>
