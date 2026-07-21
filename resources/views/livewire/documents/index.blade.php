<?php

use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Document::class, $project]);

        $this->project = $project;
    }

    /**
     * @return Collection<int, Document>
     */
    #[Computed]
    public function documents(): Collection
    {
        return $this->project->documents()->with('category')->latest()->get();
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 文書</h1>
        @can('create', [Document::class, $project])
            <a href="{{ route('documents.create', $project) }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規文書
            </a>
        @endcan
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->documents as $document)
            <li wire:key="document-{{ $document->id }}" class="px-4 py-3">
                <a href="{{ route('documents.show', [$project, $document]) }}" class="font-medium text-indigo-600 hover:underline">
                    {{ $document->title }}
                </a>
                @if ($document->category)
                    <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $document->category->name }}</span>
                @endif
                @if ($document->description)
                    <p class="text-sm text-gray-600">{{ $document->description }}</p>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-gray-500">文書がありません。</li>
        @endforelse
    </ul>
</div>
