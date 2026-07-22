<?php

use App\Models\News;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    /**
     * Matches Redmine's NewsController#index with no project_id — every
     * news item across every project the current user can view_news in,
     * newest first. Per-item visibility (permission, module enabled,
     * project not archived/closed, ...) isn't expressible as a single SQL
     * WHERE clause, so this filters in memory then paginates, the same
     * approach projects.index uses for its own can('view') filter.
     *
     * @return LengthAwarePaginator<int, News>
     */
    #[Computed]
    public function newsItems(): LengthAwarePaginator
    {
        $visible = News::query()
            ->with(['author', 'project'])
            ->withCount('comments')
            ->latest()
            ->get()
            ->filter(fn (News $news) => auth()->user()?->can('view', $news))
            ->values();

        $perPage = 10;

        return new LengthAwarePaginator(
            $visible->forPage($this->getPage(), $perPage)->values(),
            $visible->count(),
            $perPage,
            $this->getPage(),
            ['pageName' => 'page'],
        );
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">お知らせ</h1>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->newsItems as $item)
            <li wire:key="news-{{ $item->id }}" class="px-4 py-3">
                <p class="text-xs text-gray-500">
                    <a href="{{ route('projects.show', $item->project) }}" class="text-indigo-600 hover:underline">{{ $item->project->name }}</a>
                </p>
                <a href="{{ route('news.show', [$item->project, $item]) }}" class="font-medium text-indigo-600 hover:underline">
                    {{ $item->title }}
                </a>
                @if ($item->summary)
                    <p class="text-sm text-gray-600">{{ $item->summary }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-500">
                    {{ $item->author->name }} — {{ $item->created_at->format('Y-m-d H:i') }}
                    — コメント{{ $item->comments_count }}件
                </p>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-gray-500">お知らせがありません。</li>
        @endforelse
    </ul>

    <div class="mt-4">
        {{ $this->newsItems->links() }}
    </div>
</div>
