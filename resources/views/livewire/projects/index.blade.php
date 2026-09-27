<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\ReordersColumns;
use App\Concerns\SelectsPageSize;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Activity\ProjectLastActivity;
use App\Support\Authorization\AuthorizationService;
use App\Support\Export\CsvCell;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Query\DefaultProjectQuery;
use App\Support\Query\ProjectFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The project list — Redmine's ProjectsController#index driven by
 * ProjectQuery: filters, column choice and sorting over every project the
 * viewer can see. With no filter and no sort it shows the whole tree
 * (nested-set order, indented); as soon as a filter or a sort is in play it
 * becomes a flat, paginated list, like Redmine's list display. Both
 * displays share that rule: `board` shows each project as a card, `list`
 * as a table row with the chosen columns (Redmine's display_type; the
 * default comes from the setting project_list_display_type).
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

    /**
     * `board` or `list`; null means the site default.
     */
    #[Url(as: 'display_type')]
    public ?string $displayType = null;

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * Set by editQuery() while the save form is prefilled with an
     * existing query's settings, for saveQuery() to update in place
     * instead of creating a new one (A15-07b, mirroring the issue
     * list's own editQuery()).
     */
    public ?int $editingQueryId = null;

    public function mount(): void
    {
        $this->applyDefaultQuery();

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

    #[Computed]
    public function effectiveDisplayType(): string
    {
        return in_array($this->displayType, ProjectFilterFieldRegistry::DISPLAY_TYPES, true)
            ? $this->displayType
            : ProjectFilterFieldRegistry::defaultDisplayType();
    }

    public function setDisplayType(string $type): void
    {
        $this->displayType = in_array($type, ProjectFilterFieldRegistry::DISPLAY_TYPES, true) ? $type : null;
        unset($this->effectiveDisplayType);
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
            || ($this->sortKey !== null && ProjectFilterFieldRegistry::isSortable($this->sortKey, $this->availableColumns));
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
        $query = $this->projectQuery();

        return $this->isFiltering
            ? $query->paginate($this->pageSize())
            : $this->withDisplayLevels($query->get());
    }

    /**
     * Every project the list covers, in its order — the tree, or the
     * filtered and sorted matches (Redmine's project_scope).
     *
     * @return Builder<Project>
     */
    private function projectQuery(): Builder
    {
        $query = Project::query()
            ->whereIn('id', $this->visibleProjectIds)
            ->with(['parent', 'customFieldValues']);

        if ($this->bookmarkedOnly && auth()->user()) {
            $query->whereIn('id', $this->bookmarkedProjectIds);
        }

        if (! $this->isFiltering) {
            return $query->defaultOrder();
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

        return $this->applySort($query)->orderBy('_lft');
    }

    /**
     * Redmine's Project.load_last_activity_date for the listed projects,
     * read only when the list shows that column.
     *
     * @return Collection<int, Carbon>
     */
    #[Computed]
    public function lastActivityDates(): Collection
    {
        if ($this->effectiveDisplayType !== 'list' || ! in_array('last_activity_date', $this->visibleColumns, true)) {
            return collect();
        }

        $projects = $this->projects instanceof LengthAwarePaginator ? $this->projects->getCollection() : $this->projects;

        return app(ProjectLastActivity::class)->forProjects($projects, auth()->user());
    }

    /**
     * Redmine's projects.csv: every project the list covers (not just the
     * page), one row each with the chosen columns, UTF-8 with a byte-order
     * mark so Excel reads it correctly.
     */
    public function exportCsv(): StreamedResponse
    {
        $columns = $this->visibleColumns;
        $projects = $this->projectQuery()->get();
        $lastActivityDates = in_array('last_activity_date', $columns, true)
            ? app(ProjectLastActivity::class)->forProjects($projects, auth()->user())
            : collect();

        return response()->streamDownload(function () use ($columns, $projects, $lastActivityDates): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, CsvCell::row(array_map(fn (string $key) => $this->availableColumns[$key], $columns)));

            foreach ($projects as $project) {
                fputcsv($handle, CsvCell::row(array_map(fn (string $key) => $this->columnValue($project, $key, $lastActivityDates), $columns)));
            }

            fclose($handle);
        }, 'projects.csv');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Project>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Project>
     */
    private function applySort(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return ProjectFilterFieldRegistry::applySort($query, $this->sortKey, $this->sortDirection, auth()->user());
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
        if (! ProjectFilterFieldRegistry::isSortable($key, $this->availableColumns)) {
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
     *
     * @param  Collection<int, Carbon>|null  $lastActivityDates  defaults to the listed projects' (lastActivityDates())
     */
    public function columnValue(Project $project, string $key, ?Collection $lastActivityDates = null): string
    {
        if (str_starts_with($key, 'cf_')) {
            return $project->customFieldValues
                ->where('custom_field_id', (int) substr($key, 3))
                ->map(fn (\App\Models\CustomFieldValue $value) => (string) $value->displayValue())
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
            'created_at' => \App\Support\Format\DateTimes::dateTime($project->created_at) ?? '',
            'updated_at' => \App\Support\Format\DateTimes::dateTime($project->updated_at) ?? '',
            'last_activity_date' => \App\Support\Format\DateTimes::date(($lastActivityDates ?? $this->lastActivityDates)->get($project->id)) ?? '',
            default => '',
        };
    }

    /**
     * Redmine's ProjectQuery.default: a visit that names no filter, sort or
     * columns of its own opens on the user's or the site's default query.
     */
    private function applyDefaultQuery(): void
    {
        if (request()->hasAny(['columns', 'sortKey', 'activeFilterKeys', 'search', 'statusFilter', 'bookmarkedOnly', 'display_type'])) {
            return;
        }

        $query = DefaultProjectQuery::for(auth()->user());

        if ($query !== null) {
            $this->loadQuery($query->id);
        }
    }

    /**
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleGlobally(QueryType::Project, auth()->user());
    }

    /**
     * Whether the viewer may save queries (Redmine's save_queries).
     */
    #[Computed]
    public function canSaveQueries(): bool
    {
        return app(AuthorizationService::class)->canGlobally(auth()->user(), 'save_queries');
    }

    /**
     * Project queries are global, so only administrators may share one
     * (Query::resolveVisibility() with no project).
     */
    #[Computed]
    public function canManagePublicQueries(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    public function saveQuery(): void
    {
        abort_unless($this->canSaveQueries, 403);

        $editing = $this->editingQueryId !== null ? SavedQuery::where('type', QueryType::Project->value)->findOrFail($this->editingQueryId) : null;

        if ($editing !== null) {
            $this->authorize('update', $editing);
        }

        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], null);

        $attributes = [
            'name' => $data['newQueryName'],
            'project_id' => null,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => $this->visibleColumns,
            'sort_criteria' => $this->sortKey !== null ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => null,
            'options' => ['display_type' => $this->effectiveDisplayType],
        ];

        if ($editing !== null) {
            $editing->update($attributes);
            $query = $editing;
        } else {
            $query = SavedQuery::create([...$attributes, 'type' => QueryType::Project->value, 'user_id' => auth()->id()]);
        }

        $query->roles()->sync($visibility === QueryVisibility::Roles->value ? $data['newQueryRoleIds'] : []);

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', $editing !== null ? __('クエリを更新しました。') : __('クエリを保存しました。'));
    }

    /**
     * Opens the save form prefilled with an existing saved query's
     * settings, for saveQuery() to update in place — Redmine's
     * QueriesController#edit, mirrored from the issue list's own
     * editQuery() (A15-07b).
     */
    public function editQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::where('type', QueryType::Project->value)->findOrFail($queryId);
        $this->authorize('update', $query);

        $this->loadQuery($queryId);
        $this->editingQueryId = $query->id;
        $this->newQueryName = $query->name;
        $this->newQueryVisibility = $query->visibility->value;
        $this->newQueryRoleIds = $query->roles->pluck('id')->all();
        $this->showSaveForm = true;
    }

    public function cancelEditQuery(): void
    {
        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
    }

    public function deleteQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::where('type', QueryType::Project->value)->findOrFail($queryId);
        $this->authorize('delete', $query);

        $query->delete();

        if ($this->editingQueryId === $queryId) {
            $this->cancelEditQuery();
        }

        unset($this->savedQueries);
        session()->flash('status', __('クエリを削除しました。'));
    }

    public function loadQuery(int $queryId): void
    {
        $query = SavedQuery::query()
            ->where('type', QueryType::Project->value)
            ->whereNull('project_id')
            ->find($queryId);

        abort_if($query === null, 404);
        abort_unless($query->visibleTo(auth()->user()), 403);

        $this->activeFilterKeys = array_keys($query->filters ?? []);
        $this->filterOperators = [];
        $this->filterValues = [];

        foreach ($query->filters ?? [] as $key => $filter) {
            $this->filterOperators[$key] = $filter['operator'];
            $this->filterValues[$key] = $filter['values'] ?? [];
        }

        $this->columns = $query->column_names ?? [];
        $this->search = '';
        $this->statusFilter = 'all';
        $this->sortKey = null;
        $this->sortDirection = 'asc';

        if (isset($query->sort_criteria[0])) {
            [$this->sortKey, $this->sortDirection] = $query->sort_criteria[0];
        }

        // Redmine stores display_type in the query's options; a query saved
        // without one opens in the site default.
        $displayType = $query->options['display_type'] ?? null;
        $this->displayType = in_array($displayType, ProjectFilterFieldRegistry::DISPLAY_TYPES, true) ? $displayType : null;

        $this->resetPage();
        unset($this->projects, $this->isFiltering, $this->visibleColumns, $this->effectiveDisplayType, $this->lastActivityDates);
    }

    /**
     * A board card's description: Redmine's Project#short_description (cut
     * at the end of the line that passes 255 characters) through the wiki
     * Markdown renderer, as textilizable does.
     */
    public function renderedShortDescription(Project $project): string
    {
        $description = trim((string) $project->description);

        if ($description === '') {
            return '';
        }

        $short = preg_replace('/^(.{255}[^\n\r]*).*$/su', '$1...', $description) ?? $description;

        return app(WikiMarkdownRenderer::class)->render(trim($short), $project);
    }

    /**
     * The custom fields a board card shows: the ones the viewer may see
     * (ProjectFilterFieldRegistry::customFields()) that have a value.
     *
     * @return array<string, string> field name => value
     */
    public function cardCustomFieldValues(Project $project): array
    {
        $values = [];

        foreach ($this->cardCustomFields as $field) {
            $value = $this->columnValue($project, "cf_{$field->id}");

            if ($value !== '') {
                $values[$field->name] = $value;
            }
        }

        return $values;
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function cardCustomFields(): Collection
    {
        return ProjectFilterFieldRegistry::customFields(auth()->user());
    }

    public function toggleBookmark(int $projectId): void
    {
        abort_unless($this->visibleProjectIds->contains($projectId), 404);

        $user = auth()->user();

        abort_if($user === null, 403);

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
        <div class="prose prose-sm max-w-none mb-6 rounded-md border border-neutral-200 bg-surface p-4">
            {!! $this->renderedWelcomeText !!}
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('プロジェクト') }}</h1>
        <div class="flex items-center gap-2">
        <a href="{{ route('projects.atom', ['key' => auth()->user()?->atomKey()]) }}" class="text-xs text-warning hover:underline">Atom</a>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand-hovered">
                {{ __('新規プロジェクト') }}
            </a>
        @endcan
        </div>
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
        @auth
            <label class="flex items-center gap-2 pb-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model.live="bookmarkedOnly" class="rounded border-neutral-300">
                {{ __('ブックマークしたプロジェクトのみ表示') }}
            </label>
        @endauth
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="inline-flex overflow-hidden rounded-md border border-neutral-300" role="group" aria-label="{{ __('表示形式') }}">
            <button type="button" wire:click="setDisplayType('board')" aria-pressed="{{ $this->effectiveDisplayType === 'board' ? 'true' : 'false' }}"
                class="px-3 py-1 {{ $this->effectiveDisplayType === 'board' ? 'bg-neutral-900 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50' }}">{{ __('ボード') }}</button>
            <button type="button" wire:click="setDisplayType('list')" aria-pressed="{{ $this->effectiveDisplayType === 'list' ? 'true' : 'false' }}"
                class="border-l border-neutral-300 px-3 py-1 {{ $this->effectiveDisplayType === 'list' ? 'bg-neutral-900 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50' }}">{{ __('一覧') }}</button>
        </span>
        <span class="ml-2 text-neutral-500">{{ __('保存済みクエリ:') }}</span>
        @forelse ($this->savedQueries as $savedQuery)
            <x-saved-query-pill :query="$savedQuery" />
        @empty
            <span class="text-neutral-400">{{ __('なし') }}</span>
        @endforelse
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-surface p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand-hovered">{{ __('絞り込み適用') }}</button>
            @if ($this->effectiveDisplayType === 'list')
                <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-700">
                    {{ __('表示列:') }}
                    @foreach ($this->availableColumns as $columnKey => $columnLabel)
                        <label class="flex items-center gap-1" wire:key="project-column-{{ $columnKey }}">
                            <input type="checkbox" wire:model.live="columns" value="{{ $columnKey }}" class="rounded border-neutral-300">
                            {{ $columnLabel }}
                        </label>
                    @endforeach
                </div>
            @endif
            @if ($sortKey !== null)
                <button wire:click="clearSort" class="text-sm text-brand-bold hover:underline">{{ __('並べ替えを解除') }}</button>
            @endif
            @if ($this->canSaveQueries)
                <button wire:click="$toggle('showSaveForm')" class="text-sm text-brand-bold hover:underline">{{ __('クエリを保存') }}</button>
            @endif
            <button wire:click="exportCsv" class="ml-auto rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">{{ __('CSVエクスポート') }}</button>
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles"
                :editing="$editingQueryId !== null" />
        @endif

        @if ($this->effectiveDisplayType === 'list')
            <div class="mt-3">
                <x-column-order :columns="$this->visibleColumns" :labels="$this->availableColumns" />
            </div>
        @endif
    </div>

    @if ($this->effectiveDisplayType === 'board')
        <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface" data-display="board">
            @forelse ($this->projects as $project)
                <li wire:key="project-card-{{ $project->id }}" class="flex items-start justify-between px-4 py-3" style="padding-left: {{ 16 + ($project->display_level ?? 0) * 16 }}px">
                    <div class="min-w-0">
                        <a href="{{ route('projects.show', $project) }}" class="font-medium text-brand-bold hover:underline">{{ $project->name }}</a>
                        <span class="ml-2 text-xs text-neutral-500">{{ $project->identifier }}</span>
                        @unless ($project->is_public)
                            <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ __('非公開') }}</span>
                        @endunless
                        @if ($project->status !== \App\Enums\ProjectStatus::Active)
                            <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ $this->columnValue($project, 'status') }}</span>
                        @endif
                        @if ($project->description)
                            <div class="prose prose-sm mt-1 max-w-none text-neutral-600" data-project-description>{!! $this->renderedShortDescription($project) !!}</div>
                        @endif
                        @php $cardValues = $this->cardCustomFieldValues($project); @endphp
                        @if ($cardValues !== [])
                            <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-neutral-600" data-project-custom-fields>
                                @foreach ($cardValues as $fieldName => $fieldValue)
                                    <div wire:key="project-card-{{ $project->id }}-cf-{{ $loop->index }}" class="flex gap-1">
                                        <dt class="font-medium">{{ $fieldName }}:</dt>
                                        <dd>{{ $fieldValue }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                    @auth
                        <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                            class="shrink-0 text-lg leading-none {{ $this->bookmarkedProjectIds->contains($project->id) ? 'text-warning' : 'text-neutral-300 hover:text-neutral-400' }}"
                            title="{{ __('ブックマーク') }}">
                            ★
                        </button>
                    @endauth
                </li>
            @empty
                <li class="px-4 py-6 text-sm text-neutral-500">{{ __('プロジェクトがありません。') }}</li>
            @endforelse
        </ul>
    @else
        <div class="overflow-x-auto rounded-md border border-neutral-200 bg-surface">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
                    <tr>
                        @foreach ($this->visibleColumns as $columnKey)
                            <th wire:key="project-heading-{{ $columnKey }}" class="px-4 py-2">
                                @if (\App\Support\Query\ProjectFilterFieldRegistry::isSortable($columnKey, $this->availableColumns))
                                    <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-neutral-900">
                                        {{ $this->availableColumns[$columnKey] }}
                                        @if ($sortKey === $columnKey)
                                            <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                @else
                                    {{ $this->availableColumns[$columnKey] }}
                                @endif
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
                                @auth
                                    <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                                        class="shrink-0 text-lg leading-none {{ $this->bookmarkedProjectIds->contains($project->id) ? 'text-warning' : 'text-neutral-300 hover:text-neutral-400' }}"
                                        title="{{ __('ブックマーク') }}">
                                        ★
                                    </button>
                                @endauth
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
    @endif

    @if ($this->projects instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="mt-4">
            <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->projects->perPage()" :total="$this->projects->total()" /></div>
            {{ $this->projects->links() }}
        </div>
    @endif
</div>
