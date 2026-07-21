<?php

use App\Models\Board;
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
        $this->authorize('viewAny', [Board::class, $project]);

        $this->project = $project;
    }

    /**
     * @return Collection<int, Board>
     */
    #[Computed]
    public function boards(): Collection
    {
        return $this->project->boards()
            ->whereNull('parent_id')
            ->withCount('topics')
            ->with(['children' => fn ($query) => $query->withCount('topics')])
            ->orderBy('position')
            ->get();
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — フォーラム</h1>
        @can('create', [Board::class, $project])
            <a href="{{ route('boards.create', $project) }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規フォーラム
            </a>
        @endcan
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->boards as $board)
            <li wire:key="board-{{ $board->id }}" class="px-4 py-3">
                <div class="flex items-center justify-between">
                    <div>
                        <a href="{{ route('boards.show', [$project, $board]) }}" class="font-medium text-indigo-600 hover:underline">
                            {{ $board->name }}
                        </a>
                        @if ($board->description)
                            <p class="text-sm text-gray-500">{{ $board->description }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500">{{ $board->topics_count }}件のトピック</span>
                        @can('update', $board)
                            <a href="{{ route('boards.edit', [$project, $board]) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                        @endcan
                    </div>
                </div>
                @if ($board->children->isNotEmpty())
                    <ul class="mt-2 ml-6 space-y-2 border-l border-gray-200 pl-4">
                        @foreach ($board->children as $child)
                            <li wire:key="board-{{ $child->id }}" class="flex items-center justify-between">
                                <div>
                                    <a href="{{ route('boards.show', [$project, $child]) }}" class="text-sm font-medium text-indigo-600 hover:underline">
                                        {{ $child->name }}
                                    </a>
                                    @if ($child->description)
                                        <p class="text-xs text-gray-500">{{ $child->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500">{{ $child->topics_count }}件のトピック</span>
                                    @can('update', $child)
                                        <a href="{{ route('boards.edit', [$project, $child]) }}" class="text-xs text-indigo-600 hover:underline">編集</a>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-gray-500">フォーラムがありません。</li>
        @endforelse
    </ul>
</div>
