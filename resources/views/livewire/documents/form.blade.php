<?php

use App\Enums\EnumerationType;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?Document $document = null;

    public string $title = '';

    public ?int $category_id = null;

    public string $description = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    public function mount(Project $project, ?Document $document = null): void
    {
        $this->project = $project;

        if ($document?->exists) {
            $this->authorize('update', $document);

            $this->document = $document;
            $this->title = $document->title;
            $this->category_id = $document->category_id;
            $this->description = (string) $document->description;
        } else {
            $this->authorize('create', [Document::class, $project]);
        }
    }

    #[Computed]
    public function categories(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::DocumentCategory)->orderBy('position')->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('enumerations', 'id')->where('type', EnumerationType::DocumentCategory->value)],
            'description' => ['nullable', 'string'],
            'newAttachments.*' => ['file', 'max:'.intdiv(config('media-library.max_file_size'), 1024)],
        ]);
        unset($data['newAttachments']);

        if ($this->document) {
            $this->document->update($data);
            $document = $this->document;
        } else {
            $data['project_id'] = $this->project->id;
            $document = Document::create($data);
        }

        foreach ($this->newAttachments as $file) {
            $document->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        $this->redirect(route('documents.show', [$this->project, $document]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $document ? '文書を編集' : '新規文書' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">タイトル</label>
            <input type="text" wire:model="title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">カテゴリ</label>
            <select wire:model="category_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            @error('category_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="6" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">添付ファイル</label>
            <input type="file" wire:model="newAttachments" multiple class="mt-1 block w-full text-sm text-gray-700">
            @error('newAttachments.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            @if ($document?->attachments()->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach ($document->attachments() as $media)
                        <li class="text-sm text-gray-600">{{ $media->file_name }} ({{ $media->human_readable_size }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $document ? route('documents.show', [$project, $document]) : route('documents.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
