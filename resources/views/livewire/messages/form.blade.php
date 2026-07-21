<?php

use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Support\Attachments\AttachmentValidationRules;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public Board $board;

    /**
     * Named $editingMessage rather than $message — Blade's @error directive
     * assigns its own local $message variable, which would otherwise
     * silently shadow a same-named component property for the rest of the
     * template once a validation error fires.
     */
    public ?Message $editingMessage = null;

    public string $subject = '';

    public string $content = '';

    public bool $is_sticky = false;

    public bool $is_locked = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    public function mount(Project $project, Board $board, ?Message $message = null): void
    {
        $this->project = $project;
        $this->board = $board;

        if ($message?->exists) {
            $this->authorize('update', $message);

            $this->editingMessage = $message;
            $this->subject = $message->subject;
            $this->content = $message->content;
            $this->is_sticky = $message->is_sticky;
            $this->is_locked = $message->is_locked;
        } else {
            $this->authorize('create', [Message::class, $board]);
        }
    }

    #[Computed]
    public function canManageFlags(): bool
    {
        return $this->editingMessage !== null && $this->editingMessage->isTopic() && auth()->user()->can('manageFlags', $this->editingMessage);
    }

    public function save(): void
    {
        $rules = [
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'newAttachments.*' => AttachmentValidationRules::rules(),
        ];

        if ($this->canManageFlags) {
            $rules['is_sticky'] = ['boolean'];
            $rules['is_locked'] = ['boolean'];
        }

        $data = $this->validate($rules);
        unset($data['newAttachments']);

        if ($this->editingMessage) {
            $this->editingMessage->update($data);
            $message = $this->editingMessage;
            $topic = $message->isTopic() ? $message : $message->parent;
        } else {
            $message = Message::create([
                ...$data,
                'board_id' => $this->board->id,
                'author_id' => auth()->id(),
            ]);
            $topic = $message;
        }

        foreach ($this->newAttachments as $file) {
            $message->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        $this->redirect(route('messages.show', [$this->project, $this->board, $topic]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $editingMessage ? 'メッセージを編集' : '新規トピック' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">題名</label>
            <input type="text" wire:model="subject" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('subject') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">本文</label>
            <textarea wire:model="content" rows="10" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('content') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">添付ファイル</label>
            <input type="file" wire:model="newAttachments" multiple
                class="mt-1 block w-full text-sm text-gray-700">
            @error('newAttachments.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            @if ($editingMessage?->attachments()->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach ($editingMessage->attachments() as $media)
                        <li class="text-sm text-gray-600">{{ $media->file_name }} ({{ $media->human_readable_size }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->canManageFlags)
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_sticky" class="rounded border-gray-300">
                    固定表示
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_locked" class="rounded border-gray-300">
                    ロック(新しい返信を禁止)
                </label>
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $editingMessage ? route('messages.show', [$project, $board, $editingMessage->isTopic() ? $editingMessage : $editingMessage->parent]) : route('boards.show', [$project, $board]) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
