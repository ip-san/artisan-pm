<?php

use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public string $sortBy = 'category';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Document::class, $project]);

        $this->project = $project;
    }

    /**
     * Groups and orders documents the way Redmine's DocumentsController
     * #index does: by category (default), by last-updated date (newest
     * group first), or by the first letter of the title. Redmine also
     * offers an "author" grouping (by the most recent attachment's
     * uploader), which isn't supported here — attachments in this app
     * don't record who uploaded them.
     *
     * @return Collection<string, Collection<int, Document>>
     */
    #[Computed]
    public function groupedDocuments(): Collection
    {
        $documents = $this->project->documents()->with('category')->get();

        return match ($this->sortBy) {
            'date' => $documents->sortByDesc('updated_at')
                ->groupBy(fn (Document $document) => $document->updated_at->toDateString())
                ->sortKeysDesc(),
            'title' => $documents->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->groupBy(fn (Document $document) => mb_strtoupper(mb_substr($document->title, 0, 1)))
                ->sortKeys(),
            default => $documents->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->groupBy(fn (Document $document) => $document->category?->name ?? '')
                ->sortKeys(),
        };
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

    <div class="mb-4 flex items-center gap-4 text-sm">
        <span class="text-gray-500">並べ替え:</span>
        @foreach (['category' => 'カテゴリ', 'date' => '日付', 'title' => 'タイトル'] as $option => $label)
            <button type="button" wire:click="$set('sortBy', '{{ $option }}')"
                class="{{ $sortBy === $option ? 'font-semibold text-indigo-600' : 'text-gray-600 hover:underline' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @forelse ($this->groupedDocuments as $groupKey => $documents)
        <h2 class="mt-4 mb-1 text-sm font-semibold text-gray-900">
            {{ $sortBy === 'category' ? ($groupKey !== '' ? $groupKey : '未分類') : $groupKey }}
        </h2>
        <ul class="mb-2 divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
            @foreach ($documents as $document)
                <li wire:key="document-{{ $document->id }}" class="px-4 py-3">
                    <a href="{{ route('documents.show', [$project, $document]) }}" class="font-medium text-indigo-600 hover:underline">
                        {{ $document->title }}
                    </a>
                    @if ($sortBy !== 'category' && $document->category)
                        <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $document->category->name }}</span>
                    @endif
                    @if ($document->description)
                        <p class="text-sm text-gray-600">{{ $document->description }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <p class="px-4 py-6 text-center text-sm text-gray-500">文書がありません。</p>
    @endforelse
</div>
