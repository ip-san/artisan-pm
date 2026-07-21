<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    public function mount(Project $project, WikiPage $wikiPage): void
    {
        $this->authorize('view', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage;
    }

    /**
     * @return Collection<int, WikiPageVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->wikiPage->versions()->with('author')->get();
    }
}; ?>

<div class="max-w-2xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-indigo-600 hover:underline">
            {{ $wikiPage->title }}
        </a>
    </p>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">履歴</h1>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @foreach ($this->versions as $version)
            <li wire:key="wiki-version-{{ $version->id }}" class="flex items-center justify-between px-4 py-2 text-sm">
                <div>
                    <a href="{{ route('wiki.version', [$project, $wikiPage, $version->version]) }}" class="text-indigo-600 hover:underline">
                        v{{ $version->version }}
                    </a>
                    <span class="text-gray-500">— {{ $version->author->name }} — {{ $version->created_at->format('Y-m-d H:i') }}</span>
                    @if ($version->comments)
                        <span class="text-gray-400">({{ $version->comments }})</span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
