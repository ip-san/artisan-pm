<?php

use App\Models\Project;
use App\Models\User;
use App\Support\Issues\SubprojectScope;
use App\Models\Setting;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use App\Support\Activity\OffByDefault;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @var array<int, string> */
    #[Url]
    public array $activeTypes = [];

    // Matches Redmine's with_subprojects param on ActivitiesController#index:
    // without the parameter it follows display_subprojects_issues (set in
    // mount()).
    #[Url]
    public bool $withSubprojects = false;

    /**
     * Redmine's user_id param (ActivitiesController#index): narrows the
     * feed to one author's events. `@author = User.visible.active.find(...)`
     * — an unknown, inactive, or invisible id 404s (User::visible() isn't
     * used since this app has no distinct user-visibility model beyond
     * being active; matching the "unknown id 404s" behavior is what matters).
     */
    #[Url]
    public ?int $userId = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;

        if (! request()->has('withSubprojects')) {
            $this->withSubprojects = SubprojectScope::enabled();
        }

        if ($this->from === '') {
            $this->from = \App\Support\Format\DateTimes::today()->subDays(Setting::get('activity_days_default', 10))->toDateString();
        }

        if ($this->to === '') {
            $this->to = \App\Support\Format\DateTimes::today()->toDateString();
        }

        // A resolved author 404s here (mount time) rather than lazily in
        // the entries() computed, matching Redmine finding @author before
        // building the event scope.
        $this->author;

        if ($this->activeTypes === [] && $this->userId !== null) {
            // An author filter (user_id) shows every event type — Redmine's
            // `@author.nil? ? ... : @activity.scope = :all` (:all means
            // every registered type, unlike the default scope, which
            // excludes off-by-default ones).
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

    #[Computed]
    public function providers(): Collection
    {
        return app(ActivityProviderRegistry::class)->all();
    }

    /**
     * Redmine's User.visible.active.find(params[:user_id]) — an unknown or
     * inactive id 404s rather than silently showing an unfiltered feed.
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
     * Redmine's activity_authors_options_for_select: "<< me >>" plus the
     * project's active members, sorted by name.
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

        return $options + User::nameOptions($this->project->users()->where('status', \App\Enums\UserStatus::Active)->get());
    }

    /**
     * This project alone, or this project plus every descendant the viewer
     * can also see — same _lft/_rgt range query search/index.blade.php's
     * own searchableProjects() already uses for its "include subprojects"
     * toggle.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function scopedProjects(): Collection
    {
        if (! $this->withSubprojects) {
            return collect([$this->project]);
        }

        return Project::query()
            ->where('_lft', '>=', $this->project->_lft)
            ->where('_rgt', '<=', $this->project->_rgt)
            ->get()
            ->filter(fn (Project $candidate) => Gate::allows('view', $candidate))
            ->values();
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

        $entries = $this->scopedProjects
            ->flatMap(fn (Project $project) => $activeProviders
                ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to)));

        if ($this->author !== null) {
            $entries = $entries->filter(fn ($entry) => $entry->authorId === $this->author->id);
        }

        return $entries->sortByDesc('occurredAt')->values();
    }

    /**
     * @return Collection<string, Collection<int, \App\Support\Activity\ActivityEntry>>
     */
    #[Computed]
    public function groupedEntries(): Collection
    {
        return $this->entries->groupBy(fn ($entry) => \App\Support\Format\DateTimes::local($entry->occurredAt)->toDateString());
    }

    public function applyFilters(): void
    {
        if (auth()->user() !== null) {
            \App\Support\Preferences\UserPreferences::save(auth()->user(), ['activity_scope' => $this->activeTypes]);
        }

        unset($this->scopedProjects, $this->entries, $this->groupedEntries);
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">
            {{ $this->author !== null ? __(':project — :user の活動', ['project' => $project->name, 'user' => $this->author->displayName()]) : __(':project — 活動', ['project' => $project->name]) }}
        </h1>
        <a href="{{ route('activity.atom', [$project, 'key' => auth()->user()?->atomKey(), 'userId' => $userId]) }}" class="text-xs text-warning hover:underline">Atom</a>
    </div>

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
        <label class="flex items-center gap-1 text-sm text-neutral-700">
            <input type="checkbox" wire:model="withSubprojects" class="rounded border-neutral-300">
            {{ __('サブプロジェクトを含む') }}
        </label>
        <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand-hovered">
            {{ __('適用') }}
        </button>
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
</div>
