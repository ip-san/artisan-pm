<?php

use App\Models\Document;
use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Document $document;

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

    public function mount(Project $project, Document $document): void
    {
        $this->authorize('view', $document);

        $this->project = $project;
        $this->document = $document->load('category');

        foreach ($this->document->attachments() as $media) {
            $this->attachmentDescriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->document);

        $this->document->delete();

        $this->redirect(route('documents.index', $this->project), navigate: true);
    }

    /**
     * Matches Redmine's Attachment#description — see the same feature on
     * issues.show for the reasoning behind reading from the bound array
     * rather than taking the value as a parameter.
     */
    public function updateAttachmentDescription(int $mediaId): void
    {
        $this->authorize('update', $this->document);

        $media = $this->document->attachments()->firstWhere('id', $mediaId);
        abort_if($media === null, 404);

        $description = trim((string) ($this->attachmentDescriptions[$mediaId] ?? ''));
        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }
}; ?>

<div class="max-w-2xl">
    <div class="flex items-start justify-between mb-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $document->title }}</h1>
            @if ($document->category)
                <span class="mt-1 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $document->category->name }}</span>
            @endif
        </div>
        <div class="flex gap-2">
            @can('update', $document)
                <a href="{{ route('documents.edit', [$project, $document]) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    編集
                </a>
            @endcan
            @can('delete', $document)
                <button wire:click="delete" wire:confirm="この文書を削除しますか?"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    削除
                </button>
            @endcan
        </div>
    </div>

    @if ($document->description)
        <div class="rounded-md border border-gray-200 bg-white p-4 mb-4">
            <p class="whitespace-pre-line text-sm text-gray-800">{{ $document->description }}</p>
        </div>
    @endif

    @php $attachments = $document->attachments(); @endphp
    <h2 class="text-sm font-semibold text-gray-900 mb-2">添付ファイル</h2>
    <ul class="space-y-1">
        @forelse ($attachments as $media)
            <li class="text-sm" wire:key="document-attachment-{{ $media->id }}">
                <div class="flex items-center gap-2">
                    <x-attachment-thumbnail :media="$media" />
                    <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                        {{ $media->file_name }}
                    </a>
                    <span class="text-gray-500">({{ $media->human_readable_size }})</span>
                    <x-download-count :media="$media" />
                </div>
                @can('update', $document)
                    <div class="mt-1 flex items-center gap-2">
                        <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                            class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                        <button wire:click="updateAttachmentDescription({{ $media->id }})"
                            class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                    </div>
                @elseif ($media->getCustomProperty('description'))
                    <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                @endcan
            </li>
        @empty
            <li class="text-sm text-gray-500">添付ファイルはありません。</li>
        @endforelse
    </ul>
</div>
