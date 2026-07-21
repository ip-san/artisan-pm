<?php

use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public News $news;

    public string $commentContent = '';

    public function mount(Project $project, News $news): void
    {
        $this->authorize('view', $news);

        $this->project = $project;
        $this->news = $news->load('author');
    }

    /**
     * @return Collection<int, NewsComment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->news->comments()->with('author')->get();
    }

    public function addComment(): void
    {
        $this->authorize('comment', $this->news);

        $data = $this->validate([
            'commentContent' => ['required', 'string'],
        ]);

        NewsComment::create([
            'news_id' => $this->news->id,
            'author_id' => auth()->id(),
            'content' => $data['commentContent'],
        ]);

        $this->reset('commentContent');
        unset($this->comments);
    }

    public function deleteComment(int $commentId): void
    {
        $comment = NewsComment::query()->where('news_id', $this->news->id)->findOrFail($commentId);

        $this->authorize('delete', $comment);

        $comment->delete();
        unset($this->comments);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->news);

        $this->news->delete();

        $this->redirect(route('news.index', $this->project), navigate: true);
    }

    public function toggleWatch(): void
    {
        $this->authorize('watch', $this->news);

        $existing = $this->news->watchers()->where('user_id', auth()->id())->first();

        if ($existing) {
            $existing->delete();
        } else {
            $this->news->watchers()->create(['user_id' => auth()->id()]);
        }

        $this->news->unsetRelation('watchers');
    }
}; ?>

<div class="max-w-2xl">
    <div class="flex items-start justify-between mb-4">
        <h1 class="text-xl font-semibold text-gray-900">{{ $news->title }}</h1>
        <div class="flex gap-2">
            @can('watch', $news)
                <button wire:click="toggleWatch" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $news->isWatchedBy(auth()->user()) ? 'ウォッチ解除' : 'ウォッチ' }}
                </button>
            @endcan
            @can('update', $news)
                <a href="{{ route('news.edit', [$project, $news]) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    編集
                </a>
            @endcan
            @can('delete', $news)
                <button wire:click="delete" wire:confirm="このお知らせを削除しますか?"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    削除
                </button>
            @endcan
        </div>
    </div>

    <p class="mb-4 text-xs text-gray-500">{{ $news->author->name }} — {{ $news->created_at->format('Y-m-d H:i') }}</p>

    <div class="rounded-md border border-gray-200 bg-white p-4 mb-4">
        <p class="whitespace-pre-line text-sm text-gray-800">{{ $news->description }}</p>
    </div>

    @php $attachments = $news->attachments(); @endphp
    @if ($attachments->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-900 mb-2">添付ファイル</h2>
        <ul class="mb-6 space-y-1">
            @foreach ($attachments as $media)
                <li class="text-sm">
                    <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                        {{ $media->file_name }}
                    </a>
                    <span class="text-gray-500">({{ $media->human_readable_size }})</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="text-sm font-semibold text-gray-900 mb-2">コメント ({{ $this->comments->count() }})</h2>
    <ul class="mb-6 space-y-3">
        @foreach ($this->comments as $comment)
            <li wire:key="news-comment-{{ $comment->id }}" class="rounded-md border border-gray-200 bg-white p-4">
                <p class="whitespace-pre-line text-sm text-gray-800">{{ $comment->content }}</p>
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>{{ $comment->author->name }} — {{ $comment->created_at->format('Y-m-d H:i') }}</span>
                    @can('delete', $comment)
                        <button wire:click="deleteComment({{ $comment->id }})" wire:confirm="このコメントを削除しますか?" class="text-red-600 hover:underline">削除</button>
                    @endcan
                </div>
            </li>
        @endforeach
    </ul>

    @can('comment', $news)
        <form wire:submit="addComment" class="space-y-2">
            <textarea wire:model="commentContent" rows="3" placeholder="コメントを追加"
                class="block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('commentContent') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                コメントを投稿
            </button>
        </form>
    @endcan
</div>
