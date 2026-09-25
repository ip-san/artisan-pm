<?php

use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Activity\CrossProjectEntries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\Activity\OffByDefault;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

/**
 * Matches Redmine's ActivitiesController#index with no bound project — the
 * per-project activity.index (unchanged) remains reachable from inside a
 * project, same relationship issues.global-index/search.global-index/
 * calendar.global-index already have with their own project-scoped
 * counterparts. Redmine's with_subprojects param is a no-op once no
 * project is bound (every visible project is already included), so it
 * isn't offered here — only on the per-project page.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @var array<int, string> */
    #[Url]
    public array $activeTypes = [];

    #[Url]
    public ?int $userId = null;

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = \App\Support\Format\DateTimes::today()->subDays(Setting::get('activity_days_default', 10))->toDateString();
        }

        if ($this->to === '') {
            $this->to = \App\Support\Format\DateTimes::today()->toDateString();
        }

        // A resolved author 404s here, matching Redmine finding @author
        // before building the event scope.
        $this->author;

        if ($this->activeTypes === [] && $this->userId !== null) {
            // An author filter (user_id) shows every event type — Redmine's
            // `@author.nil? ? ... : @activity.scope = :all`.
            $this->activeTypes = $this->providers->map->type()->values()->all();
        } elseif ($this->activeTypes === []) {
            // The types the user last applied (Redmine's activity_scope), if
            // any of them still exist; otherwise everything not off by default.
            $remembered = array_values(array_intersect((array) auth()->user()?->preference('activity_scope'), $this->providers->map->type()->all()));

            $this->activeTypes = $remembered !== []
                ? $remembered
                : $this->providers->reject(fn ($provider) => $provider instanceof OffByDefault)->map->type()->values()->all();
        }
    }

    /**
     * Redmine's User.visible.active.find(params[:user_id]).
     */
    #[Computed]
    public function author(): ?User
    {
        if ($this->userId === null) {
            return null;
        }

        return User::query()->where('status', \App\Enums\UserStatus::Active)->findOrFail($this->userId);
    }

    /**
     * "<< me >>" plus every active user with a membership on at least one
     * project the viewer can see (Redmine's Query.new(project: nil).users
     * for the cross-project case).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function authorOptions(): array
    {
        $options = [];

        if (auth()->user() !== null) {
            $options[auth()->id()] = __('<< 自分 >>');
        }

        $users = User::query()
            ->where('status', \App\Enums\UserStatus::Active)
            ->whereHas('memberships', fn ($q) => $q->whereIn('project_id', $this->visibleProjects->pluck('id')))
            ->get();

        return $options + User::nameOptions($users);
    }

    #[Computed]
    public function providers(): Collection
    {
        return app(ActivityProviderRegistry::class)->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function providerLabels(): array
    {
        return $this->providers->mapWithKeys(fn ($provider) => [$provider->type() => $provider->label()])->all();
    }

    /**
     * Every project the viewer can see at all — the same "resolve visible
     * projects once, then let each ActivityProvider apply its own view_*
     * check per project" pattern time-entries.global-index/
     * issues.global-index already use, since ActivityProvider::entries()
     * is inherently a single-project query (see its interface doc).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->get()
            ->filter(fn (Project $project) => Gate::allows('view', $project))
            ->values();
    }

    /**
     * @return Collection<int, \App\Support\Activity\ActivityEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        // The days are the viewer's: from the start of the first to the end
        // of the last in their zone.
        $from = Carbon::instance(\App\Support\Format\DateTimes::dayBounds($this->from)[0]);
        $to = Carbon::instance(\App\Support\Format\DateTimes::dayBounds($this->to)[1]);

        $activeProviders = $this->providers->filter(fn ($provider) => in_array($provider->type(), $this->activeTypes, true));

        $entries = CrossProjectEntries::collect($activeProviders, $this->visibleProjects, auth()->user(), $from, $to);

        if ($this->author !== null) {
            $entries = $entries->filter(fn ($entry) => $entry->authorId === $this->author->id)->values();
        }

        return $entries;
    }

    /**
     * @return Collection<string, Collection<int, \App\Support\Activity\ActivityEntry>>
     */
    #[Computed]
    public function groupedEntries(): Collection
    {
        return $this->entries->groupBy(fn ($entry) => \App\Support\Format\DateTimes::local($entry->occurredAt)->toDateString());
    }

    /**
     * Moves the window back by its own length, ending the day before the
     * current start (Redmine's "« 前の期間" link).
     */
    public function previousPeriod(): void
    {
        $this->shiftPeriod(-1);
    }

    public function nextPeriod(): void
    {
        $this->shiftPeriod(1);
    }

    private function shiftPeriod(int $direction): void
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $days = max(1, (int) $from->diffInDays(Carbon::parse($this->to)->startOfDay()) + 1);

        $this->from = $from->addDays($direction * $days)->toDateString();
        $this->to = Carbon::parse($this->to)->addDays($direction * $days)->toDateString();

        unset($this->entries, $this->groupedEntries);
    }

    public function applyFilters(): void
    {
        if (auth()->user() !== null) {
            \App\Support\Preferences\UserPreferences::save(auth()->user(), ['activity_scope' => $this->activeTypes]);
        }

        unset($this->visibleProjects, $this->entries, $this->groupedEntries);
    }
}; ?>

<div>
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">
        {{ $this->author !== null ? __(':user の活動', ['user' => $this->author->displayName()]) : __('活動') }}
    </h1>

    <div class="mb-6 flex flex-wrap items-end gap-4 rounded-md border border-neutral-200 bg-surface p-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('開始日') }}</label>
            <input type="date" wire:model="from" class="mt-1 block rounded-md border-neutral-300 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('終了') }}</label>
            <input type="date" wire:model="to" class="mt-1 block rounded-md border-neutral-300 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('ユーザー') }}</label>
            <select wire:model="userId" class="mt-1 block rounded-md border-neutral-300 text-sm">
                <option value="">{{ __('すべて') }}</option>
                @foreach ($this->authorOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap gap-3">
            @foreach ($this->providers as $provider)
                <label class="flex items-center gap-1 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="activeTypes" value="{{ $provider->type() }}" class="rounded border-neutral-300">
                    {{ $provider->label() }}
                </label>
            @endforeach
        </div>
        <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            {{ __('適用') }}
        </button>
        <a href="{{ route('activity.global-atom', ['key' => auth()->user()?->atomKey(), 'userId' => $userId]) }}" class="text-xs text-warning hover:underline">Atom</a>
    </div>

    @forelse ($this->groupedEntries as $date => $dayEntries)
        <div wire:key="activity-day-{{ $date }}" class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-neutral-900">{{ \App\Support\Format\DateTimes::date($date) }}</h2>
            <ul class="space-y-2">
                @foreach ($dayEntries as $entry)
                    <li wire:key="activity-{{ $entry->type }}-{{ $entry->url }}-{{ $entry->occurredAt->timestamp }}"
                        class="rounded-md border border-neutral-200 bg-surface p-3">
                        <span class="mr-2 rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
                            {{ $this->providerLabels[$entry->type] ?? $entry->type }}
                        </span>
                        <a href="{{ $entry->url }}" class="text-brand-bold hover:underline">{{ $entry->title }}</a>
                        @if ($entry->authorName)
                            <span class="text-sm text-neutral-500">— {{ $entry->authorName }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-sm text-neutral-500">{{ __('この期間の活動はありません。') }}</p>
    @endforelse

    <div class="mt-4 flex justify-between text-sm">
        <button wire:click="previousPeriod" data-activity-previous class="text-brand-bold hover:underline">« {{ __('前の期間') }}</button>
        <button wire:click="nextPeriod" data-activity-next class="text-brand-bold hover:underline">{{ __('次の期間') }} »</button>
    </div>
</div>
