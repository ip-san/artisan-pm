<?php

use App\Enums\UserStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Matches Redmine's UsersController#show (users_controller.rb): a public
 * profile page gated on User#visible? rather than an admin-only policy —
 * any authenticated viewer who can see this user at all (self, an admin,
 * a site-wide-visibility role, or a shared project) can open it. Unlike
 * the admin edit form (users.form), this is read-only and never exposes
 * anything an unauthorized viewer couldn't already see elsewhere (no
 * status/lock controls, no is_admin toggle).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        abort_if(! $user->isVisibleTo(auth()->user()), 404);

        $this->user = $user;
    }

    /**
     * Every project both this profile's user is a member of AND the
     * viewer can see — matches Redmine's
     * `@user.memberships.where(Project.visible_condition(User.current))`.
     *
     * @return Collection<int, Member>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return $this->user->memberships()
            ->with(['project', 'roles'])
            ->get()
            ->filter(fn (Member $member) => auth()->user()?->can('view', $member->project))
            ->values();
    }

    /**
     * @return array{assigned: array{open: int, total: int}, reported: array{open: int, total: int}}
     */
    #[Computed]
    public function issueCounts(): array
    {
        $assigned = Issue::query()->visibleToAcrossProjects(auth()->user(), $this->issueVisibleProjects())
            ->where('assigned_to_id', $this->user->id);
        $reported = Issue::query()->visibleToAcrossProjects(auth()->user(), $this->issueVisibleProjects())
            ->where('author_id', $this->user->id);

        return [
            'assigned' => [
                'total' => (clone $assigned)->count(),
                'open' => (clone $assigned)->whereHas('status', fn ($q) => $q->where('is_closed', false))->count(),
            ],
            'reported' => [
                'total' => (clone $reported)->count(),
                'open' => (clone $reported)->whereHas('status', fn ($q) => $q->where('is_closed', false))->count(),
            ],
        ];
    }

    /**
     * Every project the *viewer* (not the profile's user) can see at
     * all — the same "resolve once, reuse for both the activity feed and
     * (further filtered below) the issue counts" pattern
     * activity.global-index uses. Deliberately the broader ProjectPolicy
     * check rather than an Issue-specific one: the activity feed's own
     * per-provider view_* checks (inside ActivityProviderRegistry
     * entries()) do the narrowing for each module themselves, the same
     * division of responsibility activity.global-index already uses.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project))
            ->values();
    }

    /**
     * Narrower than visibleProjects(): issues.global-index's own
     * `can('viewAny', [Issue::class, $project])` check, since a project
     * being viewable at all doesn't imply the viewer holds view_issues in
     * it (e.g. a public project with the Issue tracking module disabled,
     * or a NonMember role that doesn't grant view_issues).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function issueVisibleProjects(): Collection
    {
        return $this->visibleProjects()->filter(fn (Project $project) => auth()->user()?->can('viewAny', [Issue::class, $project]));
    }

    /**
     * Approximates Redmine's `Redmine::Activity::Fetcher.new(User.current, :author => @user).events(nil, nil, limit: 10)`
     * — the last 10 events across every project the viewer can see,
     * filtered down to ones authored by this profile's user. Unlike
     * Redmine's Fetcher, which pushes both the author filter and the
     * limit into SQL, every ActivityProvider here always materializes its
     * *entire* unbounded result set for entries() (see
     * ActivityProvider's own doc — it has no author or limit parameter),
     * so an all-time window would mean scanning every issue/journal/news/
     * etc. ever created in every visible project just to keep 10. Bounded
     * to the same 30-day window activity.global-index defaults its own
     * (unfiltered, all-authors) feed to, for the same reason: this is a
     * "recent activity" section, not a complete history, and the bound
     * keeps each provider's query cheap. A profile whose most recent
     * activity happened over 30 days ago simply shows nothing here,
     * matching Redmine only for the common case, not exactly.
     *
     * Providers with no reliable User FK for their author (Document,
     * Changeset — see ActivityEntry's own doc) never contribute here, a
     * known, documented scope reduction rather than an oversight.
     *
     * @return Collection<int, \App\Support\Activity\ActivityEntry>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        $providers = app(ActivityProviderRegistry::class)->all();
        $from = now()->subDays(30)->startOfDay();
        $to = now();

        return $this->visibleProjects()
            ->flatMap(fn (Project $project) => $providers
                ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to)))
            ->filter(fn ($entry) => $entry->authorId === $this->user->id)
            ->sortByDesc('occurredAt')
            ->take(10)
            ->values();
    }

    /**
     * Matches Redmine's `(User.current == @user || User.current.admin?) && @user.groups.any?`
     * — group membership is only shown to the account's own owner or an
     * admin, not to every other viewer who can merely see this profile.
     *
     * @return Collection<int, \App\Models\Group>
     */
    #[Computed]
    public function visibleGroups(): Collection
    {
        $viewer = auth()->user();

        if ($viewer === null || ! ($viewer->is($this->user) || $viewer->is_admin)) {
            return collect();
        }

        return $this->user->groups()->get();
    }
}; ?>

<div>
    <h1 class="mb-1 text-xl font-semibold text-gray-900">{{ $user->name }}</h1>
    @if ($user->status !== UserStatus::Active)
        <span class="mb-4 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $user->status->value }}</span>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-8 md:grid-cols-2">
        <div>
            <ul class="mb-6 space-y-1 text-sm text-gray-700">
                <li><span class="text-gray-500">ログインID:</span> {{ $user->login }}</li>
                <li><span class="text-gray-500">メールアドレス:</span> {{ $user->email }}</li>
                <li><span class="text-gray-500">登録日:</span> {{ $user->created_at?->format('Y-m-d') }}</li>
                @if ($user->last_login_at)
                    <li><span class="text-gray-500">最終ログイン:</span> {{ $user->last_login_at->format('Y-m-d H:i') }}</li>
                @endif
            </ul>

            <h2 class="mb-2 text-sm font-semibold text-gray-900">課題</h2>
            <table class="mb-6 w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-1"></th>
                        <th class="py-1 text-right">未クローズ</th>
                        <th class="py-1 text-right">合計</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-100">
                        <td class="py-1">担当</td>
                        <td class="py-1 text-right">{{ $this->issueCounts['assigned']['open'] }}</td>
                        <td class="py-1 text-right">{{ $this->issueCounts['assigned']['total'] }}</td>
                    </tr>
                    <tr>
                        <td class="py-1">報告</td>
                        <td class="py-1 text-right">{{ $this->issueCounts['reported']['open'] }}</td>
                        <td class="py-1 text-right">{{ $this->issueCounts['reported']['total'] }}</td>
                    </tr>
                </tbody>
            </table>

            @if ($this->memberships->isNotEmpty())
                <h2 class="mb-2 text-sm font-semibold text-gray-900">プロジェクト</h2>
                <table class="mb-6 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-1">プロジェクト</th>
                            <th class="py-1">ロール</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->memberships as $membership)
                            <tr wire:key="membership-{{ $membership->id }}" class="border-b border-gray-100 last:border-b-0">
                                <td class="py-1">
                                    <a href="{{ route('projects.show', $membership->project) }}" class="text-indigo-600 hover:underline">
                                        {{ $membership->project->name }}
                                    </a>
                                </td>
                                <td class="py-1 text-gray-600">{{ $membership->roles->pluck('name')->join(', ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($this->visibleGroups->isNotEmpty())
                <h2 class="mb-2 text-sm font-semibold text-gray-900">グループ</h2>
                <ul class="mb-6 space-y-1 text-sm text-gray-700">
                    @foreach ($this->visibleGroups as $group)
                        <li>{{ $group->name }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            @if ($this->recentActivity->isNotEmpty())
                <h2 class="mb-2 text-sm font-semibold text-gray-900">最近の活動</h2>
                <ul class="space-y-2">
                    @foreach ($this->recentActivity as $entry)
                        <li wire:key="activity-{{ $entry->type }}-{{ $entry->url }}-{{ $entry->occurredAt->timestamp }}" class="text-sm">
                            <a href="{{ $entry->url }}" class="text-indigo-600 hover:underline">{{ $entry->title }}</a>
                            <span class="text-gray-400">({{ $entry->occurredAt->format('Y-m-d') }})</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
