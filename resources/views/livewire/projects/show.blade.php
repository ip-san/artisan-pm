<?php

use App\Enums\UserStatus;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Support\Issues\SubprojectScope;
use App\Support\Auth\RequiresPasswordConfirmation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use RequiresPasswordConfirmation;

    public Project $project;

    public string $deleteConfirmationInput = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    /**
     * This project's own logged hours — matches Redmine's project overview
     * @total_hours: this project's logged hours, plus its subprojects' when
     * display_subprojects_issues is on.
     */
    #[Computed]
    public function totalSpentHours(): float
    {
        $projects = SubprojectScope::projectsForTimeEntries($this->project, auth()->user());

        return (float) TimeEntry::query()->whereIn('project_id', $projects->pluck('id'))->sum('hours');
    }

    /**
     * Redmine's overview lists @project.children.visible: a subproject the
     * viewer may not see (a private one, for a guest or a non-member) is
     * left out.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleSubprojects(): Collection
    {
        return $this->project->children->filter(fn (Project $child) => Gate::allows('view', $child))->values();
    }

    /**
     * Redmine's overview "Issue tracking" box: open and total issues per tracker, counting the
     * subprojects' issues when display_subprojects_issues is on, and only those the viewer may see.
     * Null when the viewer may not see this project's issues at all.
     *
     * @return Collection<int, array{tracker: Tracker, open: int, total: int}>|null
     */
    #[Computed]
    public function issueCountsByTracker(): ?Collection
    {
        if (! Gate::allows('viewAny', [Issue::class, $this->project])) {
            return null;
        }

        $projects = SubprojectScope::projectsForIssues($this->project, auth()->user());
        $visible = fn () => Issue::query()->visibleToAcrossProjects(auth()->user(), $projects);
        $totals = $visible()->toBase()->selectRaw('tracker_id, count(*) as aggregate')->groupBy('tracker_id')->pluck('aggregate', 'tracker_id');
        $open = $visible()->whereIn('status_id', IssueStatus::query()->where('is_closed', false)->select('id'))
            ->toBase()->selectRaw('tracker_id, count(*) as aggregate')->groupBy('tracker_id')->pluck('aggregate', 'tracker_id');

        return $this->project->trackers()->orderBy('position')->get()
            ->map(fn (Tracker $tracker) => ['tracker' => $tracker, 'open' => (int) ($open[$tracker->id] ?? 0), 'total' => (int) ($totals[$tracker->id] ?? 0)]);
    }

    /**
     * Redmine's overview "Members" box: active members grouped by role, in role order.
     *
     * @return Collection<string, Collection<int, string>>
     */
    #[Computed]
    public function membersByRole(): Collection
    {
        return $this->project->members()
            ->with(['user', 'roles'])
            ->get()
            ->filter(fn (Member $member) => $member->user !== null && $member->user->status === UserStatus::Active)
            ->flatMap(fn (Member $member) => $member->roles->map(fn ($role) => ['role' => $role, 'name' => $member->user->displayName()]))
            ->sortBy(fn (array $entry) => $entry['role']->position)
            ->groupBy(fn (array $entry) => $entry['role']->name)
            ->map(fn (Collection $entries) => $entries->pluck('name')->unique()->sort()->values());
    }

    /**
     * The three latest news items, as on Redmine's overview; empty when news is off or hidden.
     *
     * @return Collection<int, News>
     */
    #[Computed]
    public function latestNews(): Collection
    {
        if (! Gate::allows('viewAny', [News::class, $this->project])) {
            return collect();
        }

        return News::query()->where('project_id', $this->project->id)->with('author')->latest()->limit(3)->get();
    }

    public function toggleBookmark(): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        if ($this->project->isBookmarkedBy($user)) {
            $user->bookmarkedProjects()->detach($this->project->id);
        } else {
            $user->bookmarkedProjects()->attach($this->project->id);
        }
    }

    public function closeProject(): void
    {
        $this->authorize('close', $this->project);

        $this->project->close();
    }

    public function reopenProject(): void
    {
        $this->authorize('close', $this->project);

        $this->project->reopen();
    }

    public function archiveProject(): void
    {
        $this->authorize('archive', $this->project);

        if (! $this->project->archive()) {
            $this->addError('archive', __('このプロジェクトはアーカイブできません'));
        }
    }

    public function unarchiveProject(): void
    {
        $this->authorize('archive', $this->project);

        if (! $this->project->isOpen()) {
            $this->project->unarchive();
        }
    }

    /**
     * Matches Redmine's ProjectsController#destroy: requires sudo mode
     * (recent password confirmation) and typing the project's identifier
     * — not just a yes/no confirm — since this is an irreversible,
     * cascading deletion (kalnoy/nestedset's NodeTrait deletes the whole
     * subtree; every project-scoped table already cascades via its own
     * FK). $deleteConfirmationInput is a public, client-tamperable
     * property, so it's compared server-side here rather than trusted —
     * re-running this check is required even though the button itself is
     * only rendered behind @can('delete', ...) in the view.
     */
    public function deleteProject(): void
    {
        $this->authorize('delete', $this->project);

        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        if ($this->deleteConfirmationInput !== $this->project->identifier) {
            $this->addError('deleteConfirmationInput', __('識別子が一致しません。'));

            return;
        }

        $this->project->delete();

        $this->redirect(route('projects.index'), navigate: true);
    }
}; ?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">
                {{ $project->name }}
                @unless ($project->isOpen())
                    <span class="ml-1 rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 align-middle">
                        {{ $project->status === \App\Enums\ProjectStatus::Archived ? __('アーカイブ済み') : __('クローズ') }}
                    </span>
                @endunless
            </h1>
            <p class="text-sm text-neutral-500">{{ $project->identifier }}</p>
            @error('archive') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap justify-end gap-2 whitespace-nowrap">
            @auth
                <button wire:click="toggleBookmark" class="btn btn-secondary">
                    {{ $project->isBookmarkedBy(auth()->user()) ? __('★ ブックマーク解除') : __('☆ ブックマーク') }}
                </button>
            @endauth
            @can('createSubproject', $project)
                <a href="{{ route('projects.create') }}?parent_id={{ $project->id }}"
                    class="btn btn-secondary">
                    {{ __('サブプロジェクトを追加') }}
                </a>
            @endcan
            @can('close', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Active)
                    <button wire:click="closeProject" wire:confirm="{{ __('このプロジェクトをクローズしますか?') }}"
                        class="btn btn-secondary">
                        {{ __('クローズする') }}
                    </button>
                @elseif ($project->status === \App\Enums\ProjectStatus::Closed)
                    <button wire:click="reopenProject"
                        class="btn btn-secondary">
                        {{ __('再オープン') }}
                    </button>
                @endif
            @endcan
            @can('archive', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Archived)
                    <button wire:click="unarchiveProject"
                        class="btn btn-secondary">
                        {{ __('アーカイブ解除') }}
                    </button>
                @else
                    <button wire:click="archiveProject" wire:confirm="{{ __('このプロジェクトをアーカイブしますか?アーカイブ中は編集できなくなります。') }}"
                        class="btn btn-secondary">
                        {{ __('アーカイブ') }}
                    </button>
                @endif
            @endcan
        </div>
    </div>

    @if ($project->homepage !== null && $project->homepage !== '')
        <p class="text-sm text-neutral-700 mb-2">
            <span class="text-neutral-500">{{ __('ホームページ:') }}</span>
            @if ($project->homepageUrl())
                <a href="{{ $project->homepageUrl() }}" rel="noopener noreferrer" class="text-brand-bold hover:underline">{{ $project->homepage }}</a>
            @else
                {{ $project->homepage }}
            @endif
        </p>
    @endif

    @if ($project->description)
        <p class="text-sm text-neutral-700 mb-6">{{ $project->description }}</p>
    @endif

    {{-- Redmine's overview boxes: issue tracking and spent time on the left, members and news on the right. --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="space-y-6">
            @if ($this->issueCountsByTracker !== null)
                <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-overview="issues">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-neutral-900"><x-icon name="issue" class="size-4 text-neutral-500" />{{ __('課題トラッキング') }}</h2>
                    @if ($this->issueCountsByTracker->isNotEmpty())
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                                    <th class="py-1.5 font-medium"></th>
                                    <th class="py-1.5 text-right font-medium">{{ __('未完了') }}</th>
                                    <th class="py-1.5 text-right font-medium">{{ __('合計') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->issueCountsByTracker as $row)
                                    <tr wire:key="tracker-count-{{ $row['tracker']->id }}" class="border-b border-neutral-100 last:border-b-0">
                                        <td class="py-1.5 text-neutral-700">{{ $row['tracker']->name }}</td>
                                        <td class="py-1.5 text-right font-semibold text-neutral-900 tabular-nums">{{ $row['open'] }}</td>
                                        <td class="py-1.5 text-right text-neutral-600 tabular-nums">{{ $row['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <a href="{{ route('issues.index', $project) }}" class="text-brand-bold hover:underline">{{ __('すべての課題を見る') }}</a>
                        <a href="{{ route('issues.report', $project) }}" class="text-brand-bold hover:underline">{{ __('レポート') }}</a>
                    </div>
                </section>
            @endif

            @can('viewAny', [\App\Models\TimeEntry::class, $project])
                @if ($this->totalSpentHours > 0)
                    <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-overview="time">
                        <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-neutral-900"><x-icon name="clock" class="size-4 text-neutral-500" />{{ __('実績工数') }}</h2>
                        <p class="text-2xl font-semibold text-neutral-900 tabular-nums">{{ __(':hours 時間', ['hours' => \App\Support\Format\Hours::format($this->totalSpentHours)]) }}</p>
                        <a href="{{ route('time-entries.index', $project) }}" class="mt-2 inline-block text-sm text-brand-bold hover:underline">{{ __('詳細') }}</a>
                    </section>
                @endif
            @endcan
        </div>

        <div class="space-y-6">
            @if ($this->membersByRole->isNotEmpty())
                <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-overview="members">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-neutral-900"><x-icon name="users" class="size-4 text-neutral-500" />{{ __('メンバー') }}</h2>
                    <dl class="space-y-2 text-sm">
                        @foreach ($this->membersByRole as $roleName => $names)
                            <div wire:key="role-{{ $loop->index }}">
                                <dt class="text-xs font-medium text-neutral-500">{{ $roleName }}</dt>
                                <dd class="text-neutral-800">{{ $names->join(', ') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            @if ($this->latestNews->isNotEmpty())
                <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-overview="news">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-neutral-900"><x-icon name="news" class="size-4 text-neutral-500" />{{ __('最新のお知らせ') }}</h2>
                    <ul class="space-y-3 text-sm">
                        @foreach ($this->latestNews as $news)
                            <li wire:key="overview-news-{{ $news->id }}">
                                <a href="{{ route('news.show', [$project, $news]) }}" class="font-medium text-brand-bold hover:underline">{{ $news->title }}</a>
                                <p class="text-xs text-neutral-500">{{ $news->author?->displayName() }} · {{ \App\Support\Format\DateTimes::dateOf($news->created_at) }}</p>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('news.index', $project) }}" class="mt-3 inline-block text-sm text-brand-bold hover:underline">{{ __('すべてのお知らせ') }}</a>
                </section>
            @endif
        </div>
    </div>

    @if ($this->visibleSubprojects->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('サブプロジェクト') }}</h2>
            <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
                @foreach ($this->visibleSubprojects as $child)
                    <li class="px-4 py-2">
                        <a href="{{ route('projects.show', $child) }}" class="text-brand-bold hover:underline">{{ $child->name }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('delete', $project)
        {{-- Kept out of the way at the end, closed; the admin project list links here with #project-delete, which opens it. --}}
        <details id="project-delete" class="mt-10 rounded-lg border border-danger-subtler bg-danger-subtlest p-4"
            @if ($errors->has('deleteConfirmationInput')) open @endif x-data x-init="if (location.hash === '#project-delete') $el.open = true">
            <summary class="cursor-pointer text-sm font-semibold text-danger-boldest">{{ __('プロジェクトの削除') }}</summary>
            <p class="mt-1 text-xs text-danger-bolder">
                @if ($project->isLeaf())
                    {{ __('この操作は取り消せません。課題・Wiki・バージョン等、このプロジェクトに属するすべてのデータが完全に削除されます。確認のため識別子「:identifier」を入力してください。', ['identifier' => $project->identifier]) }}
                @else
                    {{ __('この操作は取り消せません。課題・Wiki・バージョン等、このプロジェクトに属するすべてのデータ(サブプロジェクトを含む)が完全に削除されます。確認のため識別子「:identifier」を入力してください。', ['identifier' => $project->identifier]) }}
                @endif
            </p>
            <form wire:submit="deleteProject" class="mt-3 flex items-end gap-2">
                <div>
                    <input type="text" wire:model="deleteConfirmationInput" placeholder="{{ $project->identifier }}" aria-label="{{ __('確認のための識別子') }}"
                        class="block rounded-md border-neutral-300 shadow-sm text-sm">
                    @error('deleteConfirmationInput') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="{{ __('本当にこのプロジェクトを削除しますか?この操作は取り消せません。') }}"
                    class="btn btn-danger">
                    {{ __('削除') }}
                </button>
            </form>
        </details>
    @endcan
</div>
