<?php

use App\Models\Project;
use App\Models\WikiPage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [WikiPage::class, $project]);

        $this->project = $project;
    }

    /**
     * Every page grouped by the date its current version was written —
     * matches Redmine's WikiController#date_index (@pages_by_date, keyed
     * by content.updated_on.to_date). Newest date first.
     *
     * @return Collection<string, Collection<int, WikiPage>>
     */
    #[Computed]
    public function pagesByDate(): Collection
    {
        return $this->project->wikiPages()
            ->with('currentVersion')
            ->get()
            ->filter(fn (WikiPage $page) => $page->currentVersion !== null)
            ->groupBy(fn (WikiPage $page) => $page->currentVersion->created_at->toDateString())
            ->sortKeysDesc();
    }
}; ?>

<div class="flex items-start gap-6">
<div class="max-w-2xl flex-1">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — Wiki(日付順)</h1>
        <a href="{{ route('wiki.index', $project) }}" class="text-sm text-indigo-600 hover:underline">
            タイトル順に戻る
        </a>
    </div>

    @forelse ($this->pagesByDate as $date => $pages)
        <h2 class="mt-4 mb-1 text-sm font-semibold text-gray-900">{{ $date }}</h2>
        <ul class="mb-2 divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
            @foreach ($pages as $page)
                <li wire:key="wiki-date-{{ $page->id }}" class="px-4 py-2">
                    <a href="{{ route('wiki.show', [$project, $page]) }}" class="text-indigo-600 hover:underline">
                        {{ $page->title }}
                    </a>
                </li>
            @endforeach
        </ul>
    @empty
        <p class="px-4 py-6 text-center text-sm text-gray-500">Wikiページがありません。</p>
    @endforelse
</div>

<x-wiki-sidebar :project="$project" />
</div>
