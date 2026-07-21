<?php

use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Board $board;

    public Message $topic;

    public string $replyContent = '';

    public function mount(Project $project, Board $board, Message $message): void
    {
        $this->authorize('view', $message);

        if (! $message->isTopic()) {
            $this->redirect(route('messages.show', [$project, $board, $message->parent]), navigate: true);

            return;
        }

        $this->project = $project;
        $this->board = $board;
        $this->topic = $message->load('author');
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function replies(): Collection
    {
        return $this->topic->replies()->with('author')->get();
    }

    public function addReply(): void
    {
        $this->authorize('reply', $this->topic);

        $data = $this->validate([
            'replyContent' => ['required', 'string'],
        ]);

        Message::create([
            'board_id' => $this->board->id,
            'parent_id' => $this->topic->id,
            'author_id' => auth()->id(),
            'subject' => "RE: {$this->topic->subject}",
            'content' => $data['replyContent'],
        ]);

        $this->reset('replyContent');
        unset($this->replies);
        $this->topic->touch();
    }

    public function toggleWatch(): void
    {
        $this->authorize('watch', $this->topic);

        $existing = $this->topic->watchers()->where('user_id', auth()->id())->first();

        if ($existing) {
            $existing->delete();
        } else {
            $this->topic->watchers()->create(['user_id' => auth()->id()]);
        }

        $this->topic->unsetRelation('watchers');
    }

    public function deleteAttachment(int $messageId, int $mediaId): void
    {
        $message = Message::query()->where('board_id', $this->board->id)->findOrFail($messageId);

        $this->authorize('update', $message);

        $message->attachments()->firstWhere('id', $mediaId)?->delete();

        if (! $message->isTopic()) {
            unset($this->replies);
        }
    }

    public function deleteMessage(int $messageId): void
    {
        $message = Message::query()->where('board_id', $this->board->id)->findOrFail($messageId);

        $this->authorize('delete', $message);

        $isTopic = $message->isTopic();
        $message->delete();

        if ($isTopic) {
            $this->redirect(route('boards.show', [$this->project, $this->board]), navigate: true);

            return;
        }

        unset($this->replies);
    }
}; ?>

<div class="max-w-3xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('boards.show', [$project, $board]) }}" class="text-indigo-600 hover:underline">
            {{ $board->name }}
        </a>
    </p>

    <div class="flex items-start justify-between mb-4">
        <h1 class="text-xl font-semibold text-gray-900">
            @if ($topic->is_sticky)
                <span class="mr-1 text-amber-500" title="固定表示">📌</span>
            @endif
            @if ($topic->is_locked)
                <span class="mr-1 text-gray-400" title="ロック済み">🔒</span>
            @endif
            {{ $topic->subject }}
        </h1>
        <div class="flex gap-2">
            @can('watch', $topic)
                <button wire:click="toggleWatch" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $topic->isWatchedBy(auth()->user()) ? 'ウォッチ解除' : 'ウォッチ' }}
                </button>
            @endcan
            @can('update', $topic)
                <a href="{{ route('messages.edit', [$project, $board, $topic]) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    編集
                </a>
            @endcan
            @can('delete', $topic)
                <button wire:click="deleteMessage({{ $topic->id }})" wire:confirm="このトピックと返信をすべて削除しますか?"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    削除
                </button>
            @endcan
        </div>
    </div>

    <div class="rounded-md border border-gray-200 bg-white p-4 mb-2">
        <p class="whitespace-pre-line text-sm text-gray-800">{{ $topic->content }}</p>
    </div>
    @if ($topic->attachments()->isNotEmpty())
        <ul class="mb-2 space-y-1">
            @foreach ($topic->attachments() as $media)
                <li class="flex items-center justify-between text-sm">
                    <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                        {{ $media->file_name }}
                    </a>
                    <span class="flex items-center gap-2">
                        <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                        <x-download-count :media="$media" />
                        @can('update', $topic)
                            <button wire:click="deleteAttachment({{ $topic->id }}, {{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                class="text-red-600 hover:underline">削除</button>
                        @endcan
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
    <p class="mb-6 text-xs text-gray-500">{{ $topic->author->name }} — {{ $topic->created_at->format('Y-m-d H:i') }}</p>

    <h2 class="text-sm font-semibold text-gray-900 mb-2">返信 ({{ $this->replies->count() }})</h2>
    <ul class="mb-6 space-y-3">
        @foreach ($this->replies as $reply)
            <li wire:key="reply-{{ $reply->id }}" class="rounded-md border border-gray-200 bg-white p-4">
                <p class="whitespace-pre-line text-sm text-gray-800">{{ $reply->content }}</p>
                @if ($reply->attachments()->isNotEmpty())
                    <ul class="mt-2 space-y-1">
                        @foreach ($reply->attachments() as $media)
                            <li class="flex items-center justify-between text-sm">
                                <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                                    {{ $media->file_name }}
                                </a>
                                <span class="flex items-center gap-2">
                                    <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                                    <x-download-count :media="$media" />
                                    @can('update', $reply)
                                        <button wire:click="deleteAttachment({{ $reply->id }}, {{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                            class="text-red-600 hover:underline">削除</button>
                                    @endcan
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>{{ $reply->author->name }} — {{ $reply->created_at->format('Y-m-d H:i') }}</span>
                    <span class="flex gap-2">
                        @can('update', $reply)
                            <a href="{{ route('messages.edit', [$project, $board, $reply]) }}" class="text-indigo-600 hover:underline">編集</a>
                        @endcan
                        @can('delete', $reply)
                            <button wire:click="deleteMessage({{ $reply->id }})" wire:confirm="この返信を削除しますか?" class="text-red-600 hover:underline">削除</button>
                        @endcan
                    </span>
                </div>
            </li>
        @endforeach
    </ul>

    @can('reply', $topic)
        <form wire:submit="addReply" class="space-y-2">
            <textarea wire:model="replyContent" rows="4" placeholder="返信を入力"
                class="block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('replyContent') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                返信を投稿
            </button>
        </form>
    @else
        @if ($topic->is_locked)
            <p class="text-sm text-gray-500">このトピックはロックされているため返信できません。</p>
        @endif
    @endcan
</div>
