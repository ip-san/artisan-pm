<?php

use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    private const int REPLIES_PER_PAGE = 25;

    public Project $project;

    public Board $board;

    public Message $topic;

    public string $replyContent = '';

    public ?int $moveToBoardId = null;

    public ?int $newWatcherId = null;

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

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

        // Every reply (not just the current pagination page) is queried
        // here so attachmentDescriptions is pre-filled regardless of which
        // page a reply's attachment ends up rendered on — replies() below
        // re-queries with its own ->paginate() rather than reusing this.
        $allReplies = $this->topic->replies()->get();
        $allAttachments = $this->topic->attachments()->concat($allReplies->flatMap(fn (Message $reply) => $reply->attachments()));

        foreach ($allAttachments as $media) {
            $this->attachmentDescriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    /**
     * @return LengthAwarePaginator<int, Message>
     */
    #[Computed]
    public function replies(): LengthAwarePaginator
    {
        return $this->topic->replies()->with('author')->paginate(self::REPLIES_PER_PAGE);
    }

    public function quote(int $messageId): void
    {
        $this->authorize('reply', $this->topic);

        $message = $messageId === $this->topic->id ? $this->topic : $this->replies->firstWhere('id', $messageId);

        if ($message === null) {
            return;
        }

        $quoted = collect(explode("\n", $message->content))->map(fn (string $line) => "> {$line}")->implode("\n");

        $this->replyContent = "{$message->author->name} wrote:\n{$quoted}\n\n";
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

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function watcherCandidates(): Collection
    {
        $watchingIds = $this->topic->watchers->pluck('user_id');

        return $this->project->users->reject(fn (User $user) => $watchingIds->contains($user->id))->values();
    }

    public function addWatcher(): void
    {
        $this->authorize('manageWatchers', $this->topic);

        $data = $this->validate([
            'newWatcherId' => ['required', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
        ]);

        $this->topic->watchers()->firstOrCreate(['user_id' => $data['newWatcherId']]);

        $this->reset('newWatcherId');
        $this->topic->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function removeWatcher(int $userId): void
    {
        $this->authorize('manageWatchers', $this->topic);

        $this->topic->watchers()->where('user_id', $userId)->delete();

        $this->topic->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    /**
     * @return Collection<int, Board>
     */
    #[Computed]
    public function otherBoards(): Collection
    {
        return Board::query()
            ->where('project_id', $this->project->id)
            ->where('id', '!=', $this->board->id)
            ->orderBy('position')
            ->get();
    }

    public function moveTopic(): void
    {
        $this->authorize('manageFlags', $this->topic);

        $data = $this->validate([
            'moveToBoardId' => ['required', Rule::exists('boards', 'id')->where('project_id', $this->project->id)],
        ]);

        $newBoard = Board::findOrFail($data['moveToBoardId']);

        // Replies carry their own board_id rather than inheriting it from
        // the topic, so both need updating to keep them together.
        Message::query()
            ->where(fn ($q) => $q->whereKey($this->topic->id)->orWhere('parent_id', $this->topic->id))
            ->update(['board_id' => $newBoard->id]);

        $this->redirect(route('messages.show', [$this->project, $newBoard, $this->topic]), navigate: true);
    }

    public function deleteAttachment(int $messageId, int $mediaId): void
    {
        $message = $this->messageInBoard($messageId);

        $this->authorize('update', $message);

        $message->attachments()->firstWhere('id', $mediaId)?->delete();

        if (! $message->isTopic()) {
            unset($this->replies);
        }
    }

    /**
     * Matches Redmine's Attachment#description — see the same feature on
     * issues.show for the reasoning behind reading from the bound array
     * rather than taking the value as a parameter. Scoped to this board
     * the same way deleteAttachment() is, so a message id from another
     * board can't be targeted.
     */
    public function updateAttachmentDescription(int $messageId, int $mediaId): void
    {
        $message = $this->messageInBoard($messageId);

        $this->authorize('update', $message);

        $media = $message->attachments()->firstWhere('id', $mediaId);
        abort_if($media === null, 404);

        $description = trim((string) ($this->attachmentDescriptions[$mediaId] ?? ''));
        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }

    public function deleteMessage(int $messageId): void
    {
        $message = $this->messageInBoard($messageId);

        $this->authorize('delete', $message);

        $isTopic = $message->isTopic();
        $message->delete();

        if ($isTopic) {
            $this->redirect(route('boards.show', [$this->project, $this->board]), navigate: true);

            return;
        }

        unset($this->replies);
    }

    /**
     * Looks up a topic or reply, scoped to this board so a message id from
     * another board can't be targeted.
     */
    private function messageInBoard(int $messageId): Message
    {
        return Message::query()->where('board_id', $this->board->id)->findOrFail($messageId);
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

    @if ($topic->watchers->isNotEmpty() || auth()->user()?->can('manageWatchers', $topic))
        <h2 class="text-sm font-semibold text-gray-900 mb-2">ウォッチャー ({{ $topic->watchers->count() }})</h2>
        <ul class="mb-3 flex flex-wrap gap-2">
            @foreach ($topic->watchers as $watcher)
                <li class="flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                    {{ $watcher->user->name }}
                    @can('manageWatchers', $topic)
                        <button wire:click="removeWatcher({{ $watcher->user_id }})" class="text-gray-400 hover:text-red-600" title="ウォッチャーから削除">×</button>
                    @endcan
                </li>
            @endforeach
        </ul>

        @can('manageWatchers', $topic)
            @if ($this->watcherCandidates->isNotEmpty())
                <form wire:submit="addWatcher" class="mb-4 flex items-end gap-2">
                    <div>
                        <select wire:model="newWatcherId" class="block rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">ウォッチャーを追加...</option>
                            @foreach ($this->watcherCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        追加
                    </button>
                </form>
                @error('newWatcherId') <p class="-mt-2 mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
            @endif
        @endcan
    @endif

    @can('manageFlags', $topic)
        @if ($this->otherBoards->isNotEmpty())
            <form wire:submit="moveTopic" class="mb-6 flex items-end gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-700">別のフォーラムへ移動</label>
                    <select wire:model="moveToBoardId" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="">選択してください</option>
                        @foreach ($this->otherBoards as $otherBoard)
                            <option value="{{ $otherBoard->id }}">{{ $otherBoard->name }}</option>
                        @endforeach
                    </select>
                    @error('moveToBoardId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    移動
                </button>
            </form>
        @endif
    @endcan

    <div class="rounded-md border border-gray-200 bg-white p-4 mb-2">
        <p class="whitespace-pre-line text-sm text-gray-800">{{ $topic->content }}</p>
        @can('reply', $topic)
            <button wire:click="quote({{ $topic->id }})" class="mt-1 text-xs text-indigo-600 hover:underline">引用</button>
        @endcan
    </div>
    @if ($topic->attachments()->isNotEmpty())
        <ul class="mb-2 space-y-1">
            @foreach ($topic->attachments() as $media)
                <li class="text-sm" wire:key="topic-attachment-{{ $media->id }}">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <x-attachment-thumbnail :media="$media" />
                            <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                                {{ $media->file_name }}
                            </a>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                            <x-download-count :media="$media" />
                            @can('update', $topic)
                                <button wire:click="deleteAttachment({{ $topic->id }}, {{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                    class="text-red-600 hover:underline">削除</button>
                            @endcan
                        </span>
                    </div>
                    @can('update', $topic)
                        <div class="mt-1 flex items-center gap-2">
                            <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                            <button wire:click="updateAttachmentDescription({{ $topic->id }}, {{ $media->id }})"
                                class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                        </div>
                    @elseif ($media->getCustomProperty('description'))
                        <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
    <p class="mb-6 text-xs text-gray-500">{{ $topic->author->name }} — {{ $topic->created_at->format('Y-m-d H:i') }}</p>

    <h2 class="text-sm font-semibold text-gray-900 mb-2">返信 ({{ $this->replies->total() }})</h2>
    <ul class="mb-2 space-y-3">
        @foreach ($this->replies as $reply)
            <li wire:key="reply-{{ $reply->id }}" class="rounded-md border border-gray-200 bg-white p-4">
                <p class="whitespace-pre-line text-sm text-gray-800">{{ $reply->content }}</p>
                @can('reply', $topic)
                    <button wire:click="quote({{ $reply->id }})" class="text-xs text-indigo-600 hover:underline">引用</button>
                @endcan
                @if ($reply->attachments()->isNotEmpty())
                    <ul class="mt-2 space-y-1">
                        @foreach ($reply->attachments() as $media)
                            <li class="text-sm" wire:key="reply-attachment-{{ $media->id }}">
                                <div class="flex items-center justify-between">
                                    <span class="flex items-center gap-2">
                                        <x-attachment-thumbnail :media="$media" />
                                        <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                                            {{ $media->file_name }}
                                        </a>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                                        <x-download-count :media="$media" />
                                        @can('update', $reply)
                                            <button wire:click="deleteAttachment({{ $reply->id }}, {{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                                class="text-red-600 hover:underline">削除</button>
                                        @endcan
                                    </span>
                                </div>
                                @can('update', $reply)
                                    <div class="mt-1 flex items-center gap-2">
                                        <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                            class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                                        <button wire:click="updateAttachmentDescription({{ $reply->id }}, {{ $media->id }})"
                                            class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                                    </div>
                                @elseif ($media->getCustomProperty('description'))
                                    <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                                @endcan
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

    <div class="mb-6">
        {{ $this->replies->links() }}
    </div>

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
