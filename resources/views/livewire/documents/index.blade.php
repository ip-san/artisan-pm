<?php

use App\Models\Document;
use App\Models\Project;
use App\Support\Attachments\AttachmentUploader;
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
     * group first), by the first letter of the title, or by author — the
     * uploader of the document's most recent attachment (Redmine's
     * `sort_by=author`); a document with no attachment, or one whose
     * uploader is unknown, falls into the unnamed group.
     *
     * @return Collection<string, Collection<int, Document>>
     */
    #[Computed]
    public function groupedDocuments(): Collection
    {
        $documents = $this->project->documents()->with(['category', 'media'])->get();

        return match ($this->sortBy) {
            'date' => $documents->sortByDesc('updated_at')
                ->groupBy(fn (Document $document) => $document->updated_at->toDateString())
                ->sortKeysDesc(),
            'title' => $documents->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->groupBy(fn (Document $document) => mb_strtoupper(mb_substr($document->title, 0, 1)))
                ->sortKeys(),
            'author' => $this->groupedByAuthor($documents),
            default => $documents->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->groupBy(fn (Document $document) => $document->category?->name ?? '')
                ->sortKeys(),
        };
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return Collection<string, Collection<int, Document>>
     */
    private function groupedByAuthor(Collection $documents): Collection
    {
        $uploaders = AttachmentUploader::usersFor($documents->flatMap(fn (Document $document) => $document->media));

        return $documents->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->groupBy(function (Document $document) use ($uploaders) {
                $latest = $document->media->where('collection_name', 'attachments')->sortByDesc('created_at')->first();
                $uploaderId = $latest !== null ? AttachmentUploader::idOf($latest) : null;

                return $uploaderId !== null ? ($uploaders[$uploaderId]->name ?? '') : '';
            })
            ->sortKeys();
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — 文書</h1>
        @can('create', [Document::class, $project])
            <a href="{{ route('documents.create', $project) }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                新規文書
            </a>
        @endcan
    </div>

    <div class="mb-4 flex items-center gap-4 text-sm">
        <span class="text-neutral-500">並べ替え:</span>
        @foreach (['category' => 'カテゴリ', 'date' => '日付', 'title' => 'タイトル', 'author' => '作成者'] as $option => $label)
            <button type="button" wire:click="$set('sortBy', '{{ $option }}')"
                class="{{ $sortBy === $option ? 'font-semibold text-brand-bold' : 'text-neutral-600 hover:underline' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @forelse ($this->groupedDocuments as $groupKey => $documents)
        <h2 class="mt-4 mb-1 text-sm font-semibold text-neutral-900">
            {{ $sortBy === 'category' ? ($groupKey !== '' ? $groupKey : '未分類') : ($sortBy === 'author' && $groupKey === '' ? '(不明)' : $groupKey) }}
        </h2>
        <ul class="mb-2 divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
            @foreach ($documents as $document)
                <li wire:key="document-{{ $document->id }}" class="px-4 py-3">
                    <a href="{{ route('documents.show', [$project, $document]) }}" class="font-medium text-brand-bold hover:underline">
                        {{ $document->title }}
                    </a>
                    @if ($sortBy !== 'category' && $document->category)
                        <span class="ml-1 rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ $document->category->name }}</span>
                    @endif
                    @if ($document->description)
                        <p class="text-sm text-neutral-600">{{ $document->description }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <p class="px-4 py-6 text-center text-sm text-neutral-500">文書がありません。</p>
    @endforelse
</div>
