<?php

use App\Models\Project;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;

        if ($this->from === '') {
            $this->from = now()->subDays(7)->toDateString();
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
     * @return Collection<int, \App\Support\Activity\ActivityEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();

        return $this->providers
            ->filter(fn ($provider) => in_array($provider->type(), $this->activeTypes, true))
            ->flatMap(fn ($provider) => $provider->entries($this->project, auth()->user(), $from, $to))
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
        unset($this->entries, $this->groupedEntries);
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 活動</h1>
        <a href="{{ route('activity.atom', $project) }}" class="text-xs text-orange-600 hover:underline">Atom</a>
    </div>

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
