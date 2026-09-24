<?php

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\Version;
use App\Support\Issues\SubprojectScope;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    /**
     * Redmine's with_subprojects param: '1'/'0', or empty for the
     * display_subprojects_issues default.
     */
    #[Url(as: 'with_subprojects', except: '')]
    public string $withSubprojects = '';

    public function mount(Project $project): void
    {
        $this->authorize('viewRoadmap', [Version::class, $project]);

        $this->project = $project;
    }

    public function hasSubprojects(): bool
    {
        return $this->project->_rgt - $this->project->_lft > 1;
    }

    #[Computed]
    public function includesSubprojects(): bool
    {
        return $this->withSubprojects === '' ? SubprojectScope::enabled() : $this->withSubprojects === '1';
    }

    public function toggleSubprojects(): void
    {
        $this->withSubprojects = $this->includesSubprojects ? '0' : '1';

        unset($this->includesSubprojects, $this->versions);
    }

    /**
     * Not-yet-completed versions only, due-soonest first (no due date
     * sorts last, then by name) — matches Redmine's roadmap default of
     * hiding completed versions unless explicitly asked to include them,
     * a toggle this page doesn't offer (a documented, intentional scope
     * cut). With subprojects, the versions of the subprojects whose issues
     * the viewer may see are listed too (Redmine's rolled_up_versions.visible,
     * archived subprojects left out).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        $projectIds = SubprojectScope::projectsForIssuesWhen($this->project, auth()->user(), $this->includesSubprojects)->pluck('id');

        return Version::query()
            ->whereIn('project_id', $projectIds)
            ->with('project')
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->reject(fn (Version $version) => $version->isCompleted())
            ->values();
    }

    /**
     * Only trackers that opted into the roadmap count toward each
     * version's progress bar/issue counts on this page — matches
     * Redmine's own roadmap, which defaults to trackers where
     * is_in_roadmap? is true. Applied here rather than inside Version's
     * own issueCounts()/completedPercent(), since those are also used
     * where every issue should count regardless of tracker (e.g. the
     * version's own edit/show page).
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function roadmapTrackerIds(): Collection
    {
        return Tracker::query()->where('is_in_roadmap', true)->pluck('id');
    }

    /**
     * Deep-links a version's issue counts to the pre-filtered issue list —
     * matches Redmine's version_filtered_issues_path (status_id => '*'/'o'/'c'
     * in versions/_overview.html.erb), reimplemented here via issues.index's
     * own statusFilter quick-toggle rather than inventing a status_id value
     * the filter engine doesn't otherwise support.
     */
    private function issuesUrl(Version $version, string $statusFilter): string
    {
        return route('issues.index', [
            $version->project,
            'statusFilter' => $statusFilter,
            'activeFilterKeys' => ['fixed_version_id'],
            'filterOperators' => ['fixed_version_id' => '='],
            'filterValues' => ['fixed_version_id' => [$version->id]],
        ]);
    }
}; ?>

<div class="max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __(':project — ロードマップ', ['project' => $project->name]) }}</h1>
        @if ($this->hasSubprojects())
            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:click="toggleSubprojects" @checked($this->includesSubprojects) class="rounded border-neutral-300">
                {{ __('サブプロジェクト') }}
            </label>
        @endif
    </div>

    @if ($this->versions->isEmpty())
        <p class="text-sm text-neutral-500">{{ __('表示できるバージョンがありません。') }}</p>
    @endif

    <div class="space-y-6">
        @foreach ($this->versions as $version)
            @php
                $seen = $version->asSeenBy(auth()->user());
                $counts = $seen->issueCounts($this->roadmapTrackerIds);
                $total = $counts['open'] + $counts['closed'];
                $closedPercent = $seen->closedPercent($counts);
                $completedPercent = $seen->completedPercent($this->roadmapTrackerIds);
            @endphp
            <article id="roadmap-version-{{ $version->id }}" wire:key="roadmap-version-{{ $version->id }}" class="rounded-md border border-neutral-200 bg-surface p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-neutral-900">
                        <a href="{{ route('versions.edit', [$version->project, $version]) }}" class="hover:underline">{{ $version->project->isNot($project) ? $version->project->name.' - '.$version->name : $version->name }}</a>
                    </h2>
                    <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">
                        {{ match ($version->status) {
                            VersionStatus::Open => __('オープン'),
                            VersionStatus::Locked => __('ロック中'),
                            VersionStatus::Closed => __('クローズ'),
                        } }}
                    </span>
                </div>

                @if ($version->due_date)
                    @php($daysLeft = \App\Support\Format\DateTimes::daysFromToday($version->due_date))
                    <p class="mt-1 text-sm {{ $daysLeft < 0 ? 'font-medium text-danger-bolder' : 'text-neutral-600' }}">
                        {{ __('期日: :date', ['date' => \App\Support\Format\DateTimes::date($version->due_date)]) }}
                        @if ($daysLeft < 0)
                            {{ __('(:days日超過)', ['days' => -$daysLeft]) }}
                        @else
                            {{ __('(あと:days日)', ['days' => $daysLeft]) }}
                        @endif
                    </p>
                @endif

                @if ($version->description)
                    <p class="mt-2 text-sm text-neutral-700">{{ $version->description }}</p>
                @endif

                @if ($total > 0)
                    <div class="mt-3">
                        <div class="h-3 w-full overflow-hidden rounded bg-neutral-100" title="{{ __('完了率: :percent%', ['percent' => $completedPercent]) }}">
                            <div class="flex h-full">
                                <div class="h-full bg-brand-bold" style="width: {{ $closedPercent }}%"></div>
                                <div class="h-full bg-brand-subtle" style="width: {{ max(0, $completedPercent - $closedPercent) }}%"></div>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-neutral-500">
                            <a href="{{ $this->issuesUrl($version, 'all') }}" class="text-brand-bold hover:underline">{{ __(':count件の課題', ['count' => $total]) }}</a>
                            (<a href="{{ $this->issuesUrl($version, 'closed') }}" class="text-brand-bold hover:underline">{{ __('クローズ済み:count件', ['count' => $counts['closed']]) }}</a>
                            — <a href="{{ $this->issuesUrl($version, 'open') }}" class="text-brand-bold hover:underline">{{ __('オープン:count件', ['count' => $counts['open']]) }}</a>)
                            — {{ __('完了率 :percent%', ['percent' => $completedPercent]) }}
                        </p>
                    </div>
                @else
                    <p class="mt-3 text-xs text-neutral-400">{{ __('このバージョンに割り当てられた課題はありません。') }}</p>
                @endif
            </article>
        @endforeach
    </div>
</div>
