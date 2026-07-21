<?php

use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url]
    public bool $bookmarkedOnly = false;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBookmarkedOnly(): void
    {
        $this->resetPage();
    }

    /**
     * With no search or status filter active, this keeps the original
     * root-projects-only display (children are eager-loaded but not yet
     * rendered — a separate, larger piece of tree-UI work). Once either
     * filter is used, subprojects need to be findable too, so the query
     * switches to a flat, paginated list across every project regardless
     * of depth. Pagination happens after the can('view') filter (which
     * can't be expressed in SQL) rather than via ->paginate(), so the
     * page count reflects only what this user can actually see.
     *
     * @return Collection<int, Project>|LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): Collection|LengthAwarePaginator
    {
        $filtering = $this->search !== '' || $this->statusFilter !== 'all';

        $query = Project::query()->orderBy('name');

        if ($filtering) {
            if ($this->search !== '') {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('identifier', 'like', "%{$this->search}%"));
            }

            if ($this->statusFilter !== 'all') {
                $query->where('status', $this->statusFilter);
            }
        } else {
            $query->whereDoesntHave('parent')->with('children');
        }

        $visible = $query->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project))
            ->values();

        if ($this->bookmarkedOnly && auth()->user()) {
            $bookmarkedIds = auth()->user()->bookmarkedProjects()->pluck('projects.id');
            $visible = $visible->filter(fn (Project $project) => $bookmarkedIds->contains($project->id))->values();
        }

        if (! $filtering) {
            return $visible;
        }

        $perPage = 25;

        return new LengthAwarePaginator(
            $visible->forPage($this->getPage(), $perPage)->values(),
            $visible->count(),
            $perPage,
            $this->getPage(),
            ['pageName' => 'page'],
        );
    }

    public function toggleBookmark(int $projectId): void
    {
        $user = auth()->user();

        if ($user->bookmarkedProjects()->where('projects.id', $projectId)->exists()) {
            $user->bookmarkedProjects()->detach($projectId);
        } else {
            $user->bookmarkedProjects()->attach($projectId);
        }

        unset($this->projects);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">プロジェクト</h1>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.create') }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規プロジェクト
            </a>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-700">検索</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="名前・識別子で検索"
                class="mt-1 block rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700">ステータス</label>
            <select wire:model.live="statusFilter" class="mt-1 block rounded-md border-gray-300 text-sm">
                <option value="all">すべて</option>
                <option value="active">アクティブ</option>
                <option value="closed">クローズ</option>
                <option value="archived">アーカイブ済み</option>
            </select>
        </div>
    </div>

    <label class="mb-3 flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model.live="bookmarkedOnly" class="rounded border-gray-300">
        ブックマークしたプロジェクトのみ表示
    </label>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->projects as $project)
            <li class="flex items-start justify-between px-4 py-3">
                <div>
                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-indigo-600 hover:underline">
                        {{ $project->name }}
                    </a>
                    <span class="ml-2 text-xs text-gray-500">{{ $project->identifier }}</span>
                    @unless ($project->is_public)
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">非公開</span>
                    @endunless
                    @if ($project->description)
                        <p class="mt-1 text-sm text-gray-600">{{ $project->description }}</p>
                    @endif
                </div>
                <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                    class="shrink-0 text-lg leading-none {{ $project->isBookmarkedBy(auth()->user()) ? 'text-amber-500' : 'text-gray-300 hover:text-gray-400' }}"
                    title="ブックマーク">
                    ★
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">プロジェクトがありません。</li>
        @endforelse
    </ul>

    @if ($this->projects instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="mt-4">
            {{ $this->projects->links() }}
        </div>
    @endif
</div>
