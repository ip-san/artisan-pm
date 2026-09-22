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
            <p class="text-sm text-neutral-500">
                <a href="{{ route('boards.index', $project) }}" class="text-brand-bold hover:underline">フォーラム</a>
            </p>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $board->name }}</h1>
            @if ($board->description)
                <p class="text-sm text-neutral-500">{{ $board->description }}</p>
            @endif
            <a href="{{ route('boards.atom', [$project, $board, 'key' => auth()->user()?->atomKey()]) }}" class="text-xs text-warning hover:underline">Atom</a>
        </div>
        @can('create', [Message::class, $board])
            <a href="{{ route('messages.create', [$project, $board]) }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                新規トピック
            </a>
        @endcan
    </div>

    <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
                <tr>
                    <th class="px-4 py-2">題名</th>
                    <th class="px-4 py-2">作成者</th>
                    <th class="px-4 py-2">返信</th>
                    <th class="px-4 py-2">最終更新</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($this->topics as $topic)
                    <tr wire:key="topic-{{ $topic->id }}">
                        <td class="px-4 py-2">
                            @if ($topic->is_sticky)
                                <span class="mr-1 text-warning" title="固定表示">📌</span>
                            @endif
                            @if ($topic->is_locked)
                                <span class="mr-1 text-neutral-400" title="ロック済み">🔒</span>
                            @endif
                            <a href="{{ route('messages.show', [$project, $board, $topic]) }}" class="text-brand-bold hover:underline">
                                {{ $topic->subject }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-neutral-500">{{ $topic->author->displayName() }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ $topic->replies_count }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ $topic->updated_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-neutral-500">トピックがありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
