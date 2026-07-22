<?php

use App\Models\News;
use App\Models\Project;
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
     * newest first. Visibility (permission, module enabled, project not
     * archived/closed, ...) isn't expressible as a single SQL WHERE
     * clause, but it's a per-PROJECT decision, so it's resolved once per
     * project that has news at all — pagination then happens in SQL
     * rather than loading every news row to reject most of them.
     *
     * @return LengthAwarePaginator<int, News>
     */
    #[Computed]
    public function newsItems(): LengthAwarePaginator
    {
        $visibleProjectIds = Project::query()
            ->whereIn('id', News::query()->distinct()->pluck('project_id'))
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('viewAny', [News::class, $project]))
            ->pluck('id');

        return News::query()
            ->whereIn('project_id', $visibleProjectIds)
            ->with(['author', 'project'])
            ->withCount('comments')
            ->latest()
            ->paginate(10);
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
