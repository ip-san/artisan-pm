<?php

use App\Models\News;
use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?News $news = null;

    public string $title = '';

    public string $summary = '';

    public string $description = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    public function mount(Project $project, ?News $news = null): void
    {
        $this->project = $project;

        if ($news?->exists) {
            $this->authorize('update', $news);

            $this->news = $news;
            $this->title = $news->title;
            $this->summary = (string) $news->summary;
            $this->description = $news->description;
        } else {
            $this->authorize('create', [News::class, $project]);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'newAttachments.*' => ['file', 'max:'.intdiv(config('media-library.max_file_size'), 1024)],
        ]);
        unset($data['newAttachments']);

        if ($this->news) {
            $this->news->update($data);
            $news = $this->news;
        } else {
            $data['project_id'] = $this->project->id;
            $data['author_id'] = auth()->id();
            $news = News::create($data);
        }

        foreach ($this->newAttachments as $file) {
            $news->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        $this->redirect(route('news.show', [$this->project, $news]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $news ? 'お知らせを編集' : '新規お知らせ' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">タイトル</label>
            <input type="text" wire:model="title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">概要</label>
            <input type="text" wire:model="summary" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('summary') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">本文</label>
            <textarea wire:model="description" rows="10" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">添付ファイル</label>
            <input type="file" wire:model="newAttachments" multiple class="mt-1 block w-full text-sm text-gray-700">
            @error('newAttachments.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            @if ($news?->attachments()->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach ($news->attachments() as $media)
                        <li class="text-sm text-gray-600">{{ $media->file_name }} ({{ $media->human_readable_size }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $news ? route('news.show', [$project, $news]) : route('news.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
