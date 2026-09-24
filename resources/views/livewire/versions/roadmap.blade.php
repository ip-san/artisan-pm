<?php

use App\Enums\VersionStatus;
use App\Models\Issue;
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

    /** Redmine's `completed=1`: completed versions are listed too. */
    #[Url(as: 'completed', except: false)]
    public bool $showCompleted = false;

    /**
     * Redmine's `tracker_ids[]`: the trackers whose issues the roadmap
     * counts and lists; null for the default (the project's trackers shown
     * in the roadmap, Tracker#is_in_roadmap).
     *
     * @var array<int, int|string>|null
     */
    #[Url(as: 'tracker_ids', except: null)]
    public ?array $trackerIds = null;

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

        $this->forgetRoadmap();
    }

    public function toggleCompleted(): void
    {
        $this->showCompleted = ! $this->showCompleted;

        $this->forgetRoadmap();
    }

    public function toggleTracker(int $trackerId): void
    {
        $selected = $this->roadmapTrackerIds;

        $this->trackerIds = ($selected->contains($trackerId) ? $selected->reject(fn (int $id) => $id === $trackerId) : $selected->push($trackerId))
            ->values()->all();

        $this->forgetRoadmap();
    }

    private function forgetRoadmap(): void
    {
        unset($this->includesSubprojects, $this->roadmapTrackerIds, $this->projectIds, $this->candidateVersions, $this->issuesByVersion, $this->relevantVersions, $this->completedVersionIds, $this->versions, $this->completedVersions);
    }

    /**
     * The trackers the sidebar offers: the project's own (Redmine's
     * @project.trackers.sorted).
     *
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function trackers(): Collection
    {
        return $this->project->trackers()->orderBy('position')->orderBy('trackers.id')->get();
    }

    /**
     * The selected trackers (Redmine's retrieve_selected_tracker_ids): the
     * `tracker_ids` given, else the project's trackers that are shown in
     * the roadmap (is_in_roadmap). Only their issues count toward each
     * version's progress and are listed under it — applied here rather
     * than inside Version's own issueCounts()/completedPercent(), which
     * other pages use for every tracker.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function roadmapTrackerIds(): Collection
    {
        if ($this->trackerIds !== null) {
            return collect($this->trackerIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        }

        return $this->trackers->where('is_in_roadmap', true)->pluck('id')->values();
    }

    /**
     * The project, plus with subprojects the subprojects whose issues the
     * viewer may see (archived ones left out).
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function projectIds(): Collection
    {
        return SubprojectScope::projectsForIssuesWhen($this->project, auth()->user(), $this->includesSubprojects)->pluck('id');
    }

    /**
     * Redmine's @project.shared_versions, plus with subprojects their
     * versions (rolled_up_versions.visible), due-soonest first (no due
     * date last, then by name and id — Version#<=>).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function candidateVersions(): Collection
    {
        $rolledUp = Version::query()->whereIn('project_id', $this->projectIds)->with('project')->get();

        return $this->project->sharedVersions()
            ->merge($rolledUp)
            ->unique('id')
            ->sortBy([
                fn (Version $a, Version $b) => ($a->due_date === null) <=> ($b->due_date === null),
                fn (Version $a, Version $b) => $a->due_date?->toDateString() <=> $b->due_date?->toDateString(),
                fn (Version $a, Version $b) => strcmp($a->name, $b->name),
                fn (Version $a, Version $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * The visible issues of the selected trackers in the project (and its
     * subprojects when included) fixed to the listed versions, by version
     * id, in Redmine's order: project, tracker position, id.
     *
     * @return Collection<int, Collection<int, Issue>>
     */
    #[Computed]
    public function issuesByVersion(): Collection
    {
        $versionIds = $this->candidateVersions->pluck('id');

        if ($this->roadmapTrackerIds->isEmpty() || $versionIds->isEmpty()) {
            return collect();
        }

        $projects = Project::query()->whereIn('id', $this->projectIds)->get();

        return Issue::query()
            ->visibleToAcrossProjects(auth()->user(), $projects)
            ->whereIn('issues.project_id', $this->projectIds)
            ->whereIn('issues.tracker_id', $this->roadmapTrackerIds)
            ->whereIn('issues.fixed_version_id', $versionIds)
            ->join('projects', 'projects.id', '=', 'issues.project_id')
            ->join('trackers', 'trackers.id', '=', 'issues.tracker_id')
            ->orderBy('projects._lft')
            ->orderBy('trackers.position')
            ->orderBy('issues.id')
            ->select('issues.*')
            ->with(['project', 'tracker', 'status', 'assignedTo', 'assignedToGroup'])
            ->get()
            ->groupBy('fixed_version_id');
    }

    /**
     * The versions on the roadmap: the not-yet-completed ones unless
     * `completed` is on. A version shared from outside the project (and
     * its listed subprojects) only appears while some of the listed issues
     * target it, as in Redmine.
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->relevantVersions
            ->when(! $this->showCompleted, fn (Collection $versions) => $versions->reject(fn (Version $version) => $this->completedVersionIds->contains($version->id)))
            ->values();
    }

    /**
     * With completed versions hidden, the sidebar still lists them, most
     * recent first (Redmine's @completed_versions).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function completedVersions(): Collection
    {
        if ($this->showCompleted) {
            return collect();
        }

        return $this->relevantVersions->filter(fn (Version $version) => $this->completedVersionIds->contains($version->id))->reverse()->values();
    }

    /**
     * @return Collection<int, int>
     */
    #[Computed]
    public function completedVersionIds(): Collection
    {
        return $this->relevantVersions->filter(fn (Version $version) => $version->isCompleted())->pluck('id');
    }

    /**
     * @return Collection<int, Version>
     */
    #[Computed]
    public function relevantVersions(): Collection
    {
        return $this->candidateVersions
            ->filter(fn (Version $version) => $this->projectIds->contains($version->project_id) || $this->issuesByVersion->has($version->id))
            ->values();
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

<div class="flex flex-col gap-6 lg:flex-row lg:items-start">
<div class="min-w-0 max-w-3xl flex-1">
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __(':project — ロードマップ', ['project' => $project->name]) }}</h1>
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

                @php($versionIssues = $this->issuesByVersion->get($version->id, collect()))
                @if ($versionIssues->isNotEmpty())
                    <table class="mt-3 w-full text-sm" data-roadmap-issues>
                        <caption class="mb-1 text-left text-xs font-medium text-neutral-500">{{ __('関連する課題') }}</caption>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($versionIssues as $issue)
                                <tr wire:key="roadmap-issue-{{ $issue->id }}">
                                    <td class="w-7 py-1 align-middle">
                                        @if ($issue->assignedTo)
                                            <x-avatar :user="$issue->assignedTo" :size="16" />
                                        @endif
                                    </td>
                                    <td class="py-1">
                                        <a href="{{ route('issues.show', [$issue->project, $issue]) }}" @class(['hover:underline', 'text-brand-bold', 'line-through' => $issue->status?->is_closed])>
                                            @if ($issue->project->isNot($project)){{ $issue->project->name }} - @endif{{ $issue->tracker?->name }} #{{ $issue->id }}</a>: {{ $issue->subject }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </article>
        @endforeach
    </div>
</div>

<aside class="w-full shrink-0 space-y-5 text-sm lg:w-64" data-roadmap-sidebar>
    <section>
        <h2 class="mb-2 font-semibold text-neutral-900">{{ __('ロードマップ') }}</h2>
        <ul class="space-y-1">
            @foreach ($this->trackers as $tracker)
                <li wire:key="roadmap-tracker-{{ $tracker->id }}">
                    <label class="flex items-center gap-2 text-neutral-700">
                        <input type="checkbox" wire:click="toggleTracker({{ $tracker->id }})" @checked($this->roadmapTrackerIds->contains($tracker->id)) class="rounded border-neutral-300">
                        {{ $tracker->name }}
                    </label>
                </li>
            @endforeach
        </ul>
        <ul class="mt-3 space-y-1">
            <li>
                <label class="flex items-center gap-2 text-neutral-700">
                    <input type="checkbox" wire:click="toggleCompleted" @checked($showCompleted) class="rounded border-neutral-300">
                    {{ __('完了したバージョンを表示') }}
                </label>
            </li>
            @if ($this->hasSubprojects())
                <li>
                    <label class="flex items-center gap-2 text-neutral-700">
                        <input type="checkbox" wire:click="toggleSubprojects" @checked($this->includesSubprojects) class="rounded border-neutral-300">
                        {{ __('サブプロジェクト') }}
                    </label>
                </li>
            @endif
        </ul>
    </section>

    @if ($this->versions->isNotEmpty())
        <section>
            <h2 class="mb-2 font-semibold text-neutral-900">{{ __('バージョン') }}</h2>
            <ul class="space-y-1">
                @foreach ($this->versions as $version)
                    <li wire:key="roadmap-sidebar-version-{{ $version->id }}">
                        <a href="#roadmap-version-{{ $version->id }}" class="text-brand-bold hover:underline">{{ $version->project->isNot($project) ? $version->project->name.' - '.$version->name : $version->name }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->completedVersions->isNotEmpty())
        <details data-roadmap-completed-versions>
            <summary class="cursor-pointer font-semibold text-neutral-900">{{ __('完了したバージョン') }}</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($this->completedVersions as $version)
                    <li wire:key="roadmap-completed-version-{{ $version->id }}">
                        <a href="{{ route('versions.edit', [$version->project, $version]) }}" class="text-brand-bold hover:underline">{{ $version->project->isNot($project) ? $version->project->name.' - '.$version->name : $version->name }}</a>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</aside>
</div>
