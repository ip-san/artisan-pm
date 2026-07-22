<?php

use App\Models\News;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [News::class, $project]);

        $this->project = $project;
    }

    /**
     * @return Collection<int, News>
     */
    #[Computed]
    public function newsItems(): Collection
    {
        return $this->project->news()->with('author')->withCount('comments')->latest()->get();
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — お知らせ</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('news.atom', $project) }}" class="text-xs text-orange-600 hover:underline">Atom</a>
            @can('create', [News::class, $project])
                <a href="{{ route('news.create', $project) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    新規お知らせ
                </a>
            @endcan
        </div>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->newsItems as $item)
            <li wire:key="news-{{ $item->id }}" class="px-4 py-3">
                <a href="{{ route('news.show', [$project, $item]) }}" class="font-medium text-indigo-600 hover:underline">
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
</div>
