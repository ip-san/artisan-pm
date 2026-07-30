<?php

use App\Models\Project;
use App\Models\Setting;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

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

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subDays(Setting::get('activity_days_default', 7))->toDateString();
        }

        if ($this->to === '') {
            $this->to = now()->toDateString();
        }

        if ($this->activeTypes === []) {
            $this->activeTypes = $this->providers->map->type()->all();
        }
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
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project))
            ->values();
    }

    /**
     * @return Collection<int, \App\Support\Activity\ActivityEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();

        $activeProviders = $this->providers->filter(fn ($provider) => in_array($provider->type(), $this->activeTypes, true));

        return $this->visibleProjects
            ->flatMap(fn (Project $project) => $activeProviders
                ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to)))
            ->sortByDesc('occurredAt')
            ->values();
    }

    /**
     * @return Collection<string, Collection<int, \App\Support\Activity\ActivityEntry>>
     */
    #[Computed]
    public function groupedEntries(): Collection
    {
        return $this->entries->groupBy(fn ($entry) => $entry->occurredAt->toDateString());
    }

    public function applyFilters(): void
    {
        unset($this->visibleProjects, $this->entries, $this->groupedEntries);
    }
}; ?>

<div>
    <h1 class="mb-6 text-xl font-semibold text-gray-900">活動</h1>

    <div class="mb-6 flex flex-wrap items-end gap-4 rounded-md border border-gray-200 bg-white p-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">開始日</label>
            <input type="date" wire:model="from" class="mt-1 block rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">終了日</label>
            <input type="date" wire:model="to" class="mt-1 block rounded-md border-gray-300 text-sm">
        </div>
        <div class="flex flex-wrap gap-3">
            @foreach ($this->providers as $provider)
                <label class="flex items-center gap-1 text-sm text-gray-700">
                    <input type="checkbox" wire:model="activeTypes" value="{{ $provider->type() }}" class="rounded border-gray-300">
                    {{ $provider->label() }}
                </label>
            @endforeach
        </div>
        <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            適用
        </button>
    </div>

    @forelse ($this->groupedEntries as $date => $dayEntries)
        <div wire:key="activity-day-{{ $date }}" class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">{{ $date }}</h2>
            <ul class="space-y-2">
                @foreach ($dayEntries as $entry)
                    <li wire:key="activity-{{ $entry->type }}-{{ $entry->url }}-{{ $entry->occurredAt->timestamp }}"
                        class="rounded-md border border-gray-200 bg-white p-3">
                        <span class="mr-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                            {{ $this->providerLabels[$entry->type] ?? $entry->type }}
                        </span>
                        <a href="{{ $entry->url }}" class="text-indigo-600 hover:underline">{{ $entry->title }}</a>
                        @if ($entry->authorName)
                            <span class="text-sm text-gray-500">— {{ $entry->authorName }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-sm text-gray-500">この期間の活動はありません。</p>
    @endforelse
</div>
