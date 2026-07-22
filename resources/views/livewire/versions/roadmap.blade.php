<?php

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\Version;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewRoadmap', [Version::class, $project]);

        $this->project = $project;
    }

    /**
     * Not-yet-completed versions only, due-soonest first (no due date
     * sorts last) — matches Redmine's roadmap default of hiding completed
     * versions unless explicitly asked to include them, a toggle this
     * page doesn't offer (a documented, intentional scope cut).
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->project->versions()
            ->orderByRaw('due_date IS NULL, due_date ASC')
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
            $this->project,
            'statusFilter' => $statusFilter,
            'activeFilterKeys' => ['fixed_version_id'],
            'filterOperators' => ['fixed_version_id' => '='],
            'filterValues' => ['fixed_version_id' => [$version->id]],
        ]);
    }
}; ?>

<div class="max-w-3xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — ロードマップ</h1>

    @if ($this->versions->isEmpty())
        <p class="text-sm text-gray-500">表示できるバージョンがありません。</p>
    @endif

    <div class="space-y-6">
        @foreach ($this->versions as $version)
            @php
                $counts = $version->issueCounts($this->roadmapTrackerIds);
                $total = $counts['open'] + $counts['closed'];
                $closedPercent = $version->closedPercent($counts);
                $completedPercent = $version->completedPercent($this->roadmapTrackerIds);
            @endphp
            <article wire:key="roadmap-version-{{ $version->id }}" class="rounded-md border border-gray-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">
                        <a href="{{ route('versions.edit', [$project, $version]) }}" class="hover:underline">{{ $version->name }}</a>
                    </h2>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                        {{ match ($version->status) {
                            VersionStatus::Open => 'オープン',
                            VersionStatus::Locked => 'ロック中',
                            VersionStatus::Closed => 'クローズ',
                        } }}
                    </span>
                </div>

                @if ($version->due_date)
                    <p class="mt-1 text-sm {{ $version->due_date->isPast() ? 'font-medium text-red-600' : 'text-gray-600' }}">
                        期日: {{ $version->due_date->toDateString() }}
                        @if ($version->due_date->isPast())
                            ({{ $version->due_date->diffInDays(now()) }}日超過)
                        @else
                            (あと{{ now()->diffInDays($version->due_date) }}日)
                        @endif
                    </p>
                @endif

                @if ($version->description)
                    <p class="mt-2 text-sm text-gray-700">{{ $version->description }}</p>
                @endif

                @if ($total > 0)
                    <div class="mt-3">
                        <div class="h-3 w-full overflow-hidden rounded bg-gray-100" title="完了率: {{ $completedPercent }}%">
                            <div class="flex h-full">
                                <div class="h-full bg-indigo-600" style="width: {{ $closedPercent }}%"></div>
                                <div class="h-full bg-indigo-300" style="width: {{ max(0, $completedPercent - $closedPercent) }}%"></div>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            <a href="{{ $this->issuesUrl($version, 'all') }}" class="text-indigo-600 hover:underline">{{ $total }}件の課題</a>
                            (<a href="{{ $this->issuesUrl($version, 'closed') }}" class="text-indigo-600 hover:underline">クローズ済み{{ $counts['closed'] }}件</a>
                            — <a href="{{ $this->issuesUrl($version, 'open') }}" class="text-indigo-600 hover:underline">オープン{{ $counts['open'] }}件</a>)
                            — 完了率 {{ $completedPercent }}%
                        </p>
                    </div>
                @else
                    <p class="mt-3 text-xs text-gray-400">このバージョンに割り当てられた課題はありません。</p>
                @endif
            </article>
        @endforeach
    </div>
</div>
