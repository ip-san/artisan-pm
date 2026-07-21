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

    public function mount(Project $project, Board $board): void
    {
        $this->authorize('view', $board);

        $this->project = $project;
        $this->board = $board;
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function topics(): Collection
    {
        return $this->board->topics()
            ->withCount('replies')
            ->with('author')
            ->orderByDesc('is_sticky')
            ->orderByDesc('created_at')
            ->get();
    }
}; ?>

<div>
    <div class="flex items-start justify-between mb-6">
        <div>
            <p class="text-sm text-gray-500">
                <a href="{{ route('boards.index', $project) }}" class="text-indigo-600 hover:underline">フォーラム</a>
            </p>
            <h1 class="text-xl font-semibold text-gray-900">{{ $board->name }}</h1>
            @if ($board->description)
                <p class="text-sm text-gray-500">{{ $board->description }}</p>
            @endif
        </div>
        @can('create', [Message::class, $board])
            <a href="{{ route('messages.create', [$project, $board]) }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規トピック
            </a>
        @endcan
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2">題名</th>
                    <th class="px-4 py-2">作成者</th>
                    <th class="px-4 py-2">返信</th>
                    <th class="px-4 py-2">最終更新</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->topics as $topic)
                    <tr wire:key="topic-{{ $topic->id }}">
                        <td class="px-4 py-2">
                            @if ($topic->is_sticky)
                                <span class="mr-1 text-amber-500" title="固定表示">📌</span>
                            @endif
                            @if ($topic->is_locked)
                                <span class="mr-1 text-gray-400" title="ロック済み">🔒</span>
                            @endif
                            <a href="{{ route('messages.show', [$project, $board, $topic]) }}" class="text-indigo-600 hover:underline">
                                {{ $topic->subject }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $topic->author->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $topic->replies_count }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $topic->updated_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">トピックがありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
