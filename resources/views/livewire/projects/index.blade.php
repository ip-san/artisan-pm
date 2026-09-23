<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\ReordersColumns;
use App\Concerns\SelectsPageSize;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Authorization\AuthorizationService;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Query\CustomFieldFilter;
use App\Support\Query\ProjectFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The project list — Redmine's ProjectsController#index driven by
 * ProjectQuery: filters, column choice and sorting over every project the
 * viewer can see. With no filter and no sort it shows the whole tree
 * (nested-set order, indented); as soon as a filter or a sort is in play it
 * becomes a flat, paginated list, like Redmine's list display.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;
    use ReordersColumns;
    use SelectsPageSize;
    use WithPagination;

    #[Url]
    public bool $bookmarkedOnly = false;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    /** @var array<int, string> */
    #[Url]
    public array $columns = [];

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        if ($this->columns === []) {
            $this->columns = ProjectFilterFieldRegistry::defaultColumns();
        }
    }

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

    /**
     * The ids of every project the viewer may open, resolved in SQL with the
     * same rule as ProjectPolicy::view (public, or a membership whose role
     * holds view_project; archived only for administrators) — so filtering,
     * counting and paging all happen in the database and the page count
     * reflects only what this user can see.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function visibleProjectIds(): Collection
    {
        return app(AuthorizationService::class)->visibleProjectIds(auth()->user(), 'view_project');
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(ProjectFilterFieldRegistry::forViewer(auth()->user(), $this->visibleProjectIds));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function availableColumns(): array
    {
        return ProjectFilterFieldRegistry::columns(auth()->user());
    }

    /**
     * The chosen columns that exist, in the chosen order; the defaults when
     * none of them do.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function visibleColumns(): array
    {
        $chosen = array_values(array_filter($this->columns, fn ($key) => is_string($key) && array_key_exists($key, $this->availableColumns)));

        return $chosen === [] ? ProjectFilterFieldRegistry::defaultColumns() : array_values(array_unique($chosen));
    }

    /**
     * @return Collection<int, int>
     */
    #[Computed]
    public function bookmarkedProjectIds(): Collection
    {
        return auth()->user()?->bookmarkedProjects()->pluck('projects.id') ?? collect();
    }

    /**
     * Whether the list is narrowed or reordered — then it is flat and
     * paginated instead of the full tree.
     */
    #[Computed]
    public function isFiltering(): bool
    {
        return $this->search !== ''
            || $this->statusFilter !== 'all'
            || $this->builtFilters() !== []
            || $this->sortKey !== null;
    }

    /**
     * Without a filter or sort: every visible project in nested-set tree
     * order, each with a `display_level` for indentation. The level counts
     * only ancestors that are themselves in the list, like Redmine's
     * project_tree: a project whose parent is hidden starts a new top-level
     * entry instead of hanging under an invisible parent. With a filter or
     * sort: one page of the matches, flat (display_level 0), ordered by the
     * sort and then by tree position (Redmine appends `lft ASC` too).
     *
     * @return Collection<int, Project>|LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): Collection|LengthAwarePaginator
    {
        $query = Project::query()
            ->whereIn('id', $this->visibleProjectIds)
            ->with(['parent', 'customFieldValues']);

        if ($this->bookmarkedOnly && auth()->user()) {
            $query->whereIn('id', $this->bookmarkedProjectIds);
        }

        if (! $this->isFiltering) {
            return $this->withDisplayLevels($query->defaultOrder()->get());
        }

        if ($this->search !== '') {
            $like = '%'.addcslashes($this->search, '%_\\').'%';
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(fn ($q) => $q->where('name', $operator, $like)->orWhere('identifier', $operator, $like));
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $query = $this->engine->applyFilters($query, $this->builtFilters());
        $query = $this->applySort($query);

        return $query->orderBy('_lft')->paginate($this->pageSize(25));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Project>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Project>
     */
    private function applySort(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->sortKey === null || ! array_key_exists($this->sortKey, $this->availableColumns)) {
            return $query;
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if (str_starts_with($this->sortKey, 'cf_')) {
            $field = ProjectFilterFieldRegistry::customFields(auth()->user())->firstWhere('id', (int) substr($this->sortKey, 3));

            return $field !== null ? (new CustomFieldFilter($field))->applySort($query, $direction) : $query;
        }

        // Redmine sorts the parent column by tree position (lft).
        return $query->orderBy($this->sortKey === 'parent_id' ? '_lft' : $this->sortKey, $direction);
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

    public function applyFilters(): void
    {
        $this->resetPage();
        unset($this->projects, $this->isFiltering);
    }

    public function sortBy(string $key): void
    {
        if (! array_key_exists($key, $this->availableColumns)) {
            return;
        }

        if ($this->sortKey === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Back to the tree: drops the sort (filters stay).
     */
    public function clearSort(): void
    {
        $this->sortKey = null;
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    /**
     * The plain-text value of one column for one project.
     */
    public function columnValue(Project $project, string $key): string
    {
        if (str_starts_with($key, 'cf_')) {
            return $project->customFieldValues
                ->where('custom_field_id', (int) substr($key, 3))
                ->map(fn (\App\Models\CustomFieldValue $value) => (string) $value->value())
                ->filter(fn (string $value) => $value !== '')
                ->join(', ');
        }

        return match ($key) {
            'name' => $project->name,
            'identifier' => $project->identifier,
            'description' => Str::limit((string) $project->description, 255),
            'status' => match ($project->status) {
                \App\Enums\ProjectStatus::Closed => __('クローズ'),
                \App\Enums\ProjectStatus::Archived => __('アーカイブ済み'),
                default => __('アクティブ'),
            },
            'homepage' => (string) $project->homepage,
            // A parent the viewer cannot see stays unnamed.
            'parent_id' => $project->parent !== null && $this->visibleProjectIds->contains($project->parent_id) ? $project->parent->name : '',
            'is_public' => $project->is_public ? __('はい') : __('いいえ'),
            'created_at' => $project->created_at?->format('Y-m-d H:i') ?? '',
            'updated_at' => $project->updated_at?->format('Y-m-d H:i') ?? '',
            default => '',
        };
    }

    public function toggleBookmark(int $projectId): void
    {
        abort_unless($this->visibleProjectIds->contains($projectId), 404);

        $user = auth()->user();

        if ($user->bookmarkedProjects()->where('projects.id', $projectId)->exists()) {
            $user->bookmarkedProjects()->detach($projectId);
        } else {
            $user->bookmarkedProjects()->attach($projectId);
        }

        unset($this->projects, $this->bookmarkedProjectIds);
    }
}; ?>

<div>
    @if ($this->renderedWelcomeText !== '')
        <div class="prose prose-sm max-w-none mb-6 rounded-md border border-neutral-200 bg-white p-4">
            {!! $this->renderedWelcomeText !!}
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('プロジェクト') }}</h1>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('新規プロジェクト') }}
            </a>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-neutral-700">{{ __('検索') }}</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('名前・識別子で検索') }}"
                class="mt-1 block rounded-md border-neutral-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-neutral-700">{{ __('ステータス') }}</label>
            <select wire:model.live="statusFilter" class="mt-1 block rounded-md border-neutral-300 text-sm">
                <option value="all">{{ __('すべて') }}</option>
                <option value="active">{{ __('アクティブ') }}</option>
                <option value="closed">{{ __('クローズ') }}</option>
                @if (auth()->user()?->is_admin)
                    <option value="archived">{{ __('アーカイブ済み') }}</option>
                @endif
            </select>
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model.live="bookmarkedOnly" class="rounded border-neutral-300">
            {{ __('ブックマークしたプロジェクトのみ表示') }}
        </label>
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">{{ __('絞り込み適用') }}</button>
            <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-700">
                {{ __('表示列:') }}
                @foreach ($this->availableColumns as $columnKey => $columnLabel)
                    <label class="flex items-center gap-1" wire:key="project-column-{{ $columnKey }}">
                        <input type="checkbox" wire:model.live="columns" value="{{ $columnKey }}" class="rounded border-neutral-300">
                        {{ $columnLabel }}
                    </label>
                @endforeach
            </div>
            @if ($sortKey !== null)
                <button wire:click="clearSort" class="text-sm text-brand-bold hover:underline">{{ __('並べ替えを解除') }}</button>
            @endif
        </div>

        <div class="mt-3">
            <x-column-order :columns="$this->visibleColumns" :labels="$this->availableColumns" />
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
                <tr>
                    @foreach ($this->visibleColumns as $columnKey)
                        <th wire:key="project-heading-{{ $columnKey }}" class="px-4 py-2">
                            <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-neutral-900">
                                {{ $this->availableColumns[$columnKey] }}
                                @if ($sortKey === $columnKey)
                                    <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </button>
                        </th>
                    @endforeach
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($this->projects as $project)
                    <tr wire:key="project-row-{{ $project->id }}">
                        @foreach ($this->visibleColumns as $columnKey)
                            <td wire:key="project-{{ $project->id }}-{{ $columnKey }}" class="px-4 py-2 align-top"
                                @if ($loop->first) style="padding-left: {{ 16 + ($project->display_level ?? 0) * 16 }}px" @endif>
                                @if ($columnKey === 'name')
                                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-brand-bold hover:underline">{{ $project->name }}</a>
                                    @unless ($project->is_public)
                                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ __('非公開') }}</span>
                                    @endunless
                                @elseif ($columnKey === 'homepage' && $project->homepageUrl() !== null)
                                    <a href="{{ $project->homepageUrl() }}" class="text-brand-bold hover:underline" rel="noopener">{{ $project->homepage }}</a>
                                @elseif ($columnKey === 'parent_id' && $project->parent !== null && $this->visibleProjectIds->contains($project->parent->id))
                                    <a href="{{ route('projects.show', $project->parent) }}" class="text-brand-bold hover:underline">{{ $project->parent->name }}</a>
                                @else
                                    {{ $this->columnValue($project, $columnKey) }}
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-2 text-right">
                            <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                                class="shrink-0 text-lg leading-none {{ $this->bookmarkedProjectIds->contains($project->id) ? 'text-warning' : 'text-neutral-300 hover:text-neutral-400' }}"
                                title="{{ __('ブックマーク') }}">
                                ★
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($this->visibleColumns) + 1 }}" class="px-4 py-6 text-sm text-neutral-500">{{ __('プロジェクトがありません。') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->projects instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="mt-4">
            <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->projects->perPage()" :total="$this->projects->total()" /></div>
            {{ $this->projects->links() }}
        </div>
    @endif
</div>
