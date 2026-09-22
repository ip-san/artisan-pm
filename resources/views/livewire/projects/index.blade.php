<?php

use App\Concerns\SelectsPageSize;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use SelectsPageSize;
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
     * Every project the viewer can see, in nested-set tree order, each with
     * a `display_level` for indentation. The level counts only ancestors
     * that are themselves in the list, like Redmine's project_tree: a
     * project whose parent is hidden (no view permission, or filtered out by
     * the search/status filter) starts a new top-level entry instead of
     * hanging under an invisible parent. Searching therefore keeps the tree
     * shape of the matches. Pagination (25 per page) applies while a filter
     * is active and happens after the can('view') filter, which can't be
     * expressed in SQL, so the page count reflects only what this user can
     * actually see.
     *
     * @return Collection<int, Project>|LengthAwarePaginator<int, Project>
     */
    /**
     * Matches Redmine's Setting.welcome_text, shown on the home/project-list
     * page — rendered through the same Markdown pipeline as wiki pages, but
     * with no $project (this page isn't scoped to one), so [[Page]] links
     * and the {{include}}/{{child_pages}} macros are left as literal text
     * rather than resolved against an arbitrary project.
     */
    #[Computed]
    public function renderedWelcomeText(): string
    {
        $text = Setting::get('welcome_text', '');

        return $text === '' ? '' : app(WikiMarkdownRenderer::class)->render($text);
    }

    #[Computed]
    public function projects(): Collection|LengthAwarePaginator
    {
        $filtering = $this->search !== '' || $this->statusFilter !== 'all';

        $query = Project::query()->defaultOrder();

        if ($this->search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('identifier', 'like', "%{$this->search}%"));
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $visible = $query->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project))
            ->values();

        if ($this->bookmarkedOnly && auth()->user()) {
            $bookmarkedIds = auth()->user()->bookmarkedProjects()->pluck('projects.id');
            $visible = $visible->filter(fn (Project $project) => $bookmarkedIds->contains($project->id))->values();
        }

        $visible = $this->withDisplayLevels($visible);

        if (! $filtering) {
            return $visible;
        }

        $perPage = $this->pageSize(25);

        return new LengthAwarePaginator(
            $visible->forPage($this->getPage(), $perPage)->values(),
            $visible->count(),
            $perPage,
            $this->getPage(),
            ['pageName' => 'page'],
        );
    }

    /**
     * Sets `display_level` on projects given in nested-set order: the number
     * of earlier projects in the list that contain it.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Project>
     */
    private function withDisplayLevels(Collection $projects): Collection
    {
        $ancestors = [];

        foreach ($projects as $project) {
            while ($ancestors !== [] && end($ancestors)->_rgt < $project->_lft) {
                array_pop($ancestors);
            }

            $project->setAttribute('display_level', count($ancestors));
            $ancestors[] = $project;
        }

        return $projects;
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
    @if ($this->renderedWelcomeText !== '')
        <div class="prose prose-sm max-w-none mb-6 rounded-md border border-neutral-200 bg-white p-4">
            {!! $this->renderedWelcomeText !!}
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">プロジェクト</h1>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                新規プロジェクト
            </a>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-neutral-700">検索</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="名前・識別子で検索"
                class="mt-1 block rounded-md border-neutral-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-neutral-700">ステータス</label>
            <select wire:model.live="statusFilter" class="mt-1 block rounded-md border-neutral-300 text-sm">
                <option value="all">すべて</option>
                <option value="active">アクティブ</option>
                <option value="closed">クローズ</option>
                <option value="archived">アーカイブ済み</option>
            </select>
        </div>
    </div>

    <label class="mb-3 flex items-center gap-2 text-sm text-neutral-700">
        <input type="checkbox" wire:model.live="bookmarkedOnly" class="rounded border-neutral-300">
        ブックマークしたプロジェクトのみ表示
    </label>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->projects as $project)
            <li class="flex items-start justify-between px-4 py-3" style="padding-left: {{ 16 + ($project->display_level ?? 0) * 16 }}px">
                <div>
                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-brand-bold hover:underline">
                        {{ $project->name }}
                    </a>
                    <span class="ml-2 text-xs text-neutral-500">{{ $project->identifier }}</span>
                    @unless ($project->is_public)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">非公開</span>
                    @endunless
                    @if ($project->description)
                        <p class="mt-1 text-sm text-neutral-600">{{ $project->description }}</p>
                    @endif
                </div>
                <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                    class="shrink-0 text-lg leading-none {{ $project->isBookmarkedBy(auth()->user()) ? 'text-warning' : 'text-neutral-300 hover:text-neutral-400' }}"
                    title="ブックマーク">
                    ★
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">プロジェクトがありません。</li>
        @endforelse
    </ul>

    @if ($this->projects instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="mt-4">
            <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->projects->perPage()" :total="$this->projects->total()" /></div>
            {{ $this->projects->links() }}
        </div>
    @endif
</div>
