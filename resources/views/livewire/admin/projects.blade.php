<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\ReordersColumns;
use App\Concerns\SelectsPageSize;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Auth\RequiresPasswordConfirmation;
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
 * 管理 → プロジェクト — Redmine's AdminController#projects over
 * ProjectAdminQuery (6.0+): every project whatever its status, archived
 * included, through the same filters, columns and sorting as the project
 * list (ProjectFilterFieldRegistry), always as a flat table. Like Redmine's
 * query it opens filtered on status "active"; the filter can be removed.
 *
 * Row actions follow Redmine's projects context menu: one project can be
 * archived or unarchived, or deleted on its overview (identifier to type);
 * several can only be deleted together, after typing "はい" in sudo mode
 * (ProjectsController#bulk_destroy). Close/reopen stay on the overview —
 * Redmine's admin menu does not offer them either.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;
    use ReordersColumns;
    use RequiresPasswordConfirmation;
    use SelectsPageSize;
    use WithPagination;

    /** @var array<int, string> */
    #[Url]
    public array $columns = [];

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    /** @var array<int, string> */
    public array $selected = [];

    public bool $confirmingBulkDelete = false;

    public string $bulkDeleteConfirmation = '';

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        if (! request()->hasAny(['activeFilterKeys', 'columns', 'sortKey'])) {
            $this->activeFilterKeys = ['status'];
            $this->filterOperators = ['status' => FilterOperator::Equals->value];
            $this->filterValues = ['status' => [ProjectStatus::Active->value]];
        }

        if ($this->columns === []) {
            $this->columns = ProjectFilterFieldRegistry::defaultColumns();
        }
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(ProjectFilterFieldRegistry::forViewer(auth()->user(), Project::query()->pluck('id')));
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
     * @return array<int, string>
     */
    #[Computed]
    public function visibleColumns(): array
    {
        $chosen = array_values(array_filter($this->columns, fn ($key) => is_string($key) && array_key_exists($key, $this->availableColumns)));

        return $chosen === [] ? ProjectFilterFieldRegistry::defaultColumns() : array_values(array_unique($chosen));
    }

    /**
     * One page of the matching projects, in tree order unless sorted, each
     * with its depth for indenting the first column.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $query = Project::query()->withDepth()->with(['parent', 'customFieldValues']);
        $query = $this->engine->applyFilters($query, $this->builtFilters());
        $query = ProjectFilterFieldRegistry::applySort($query, $this->sortKey, $this->sortDirection, auth()->user());

        return $query->orderBy('_lft')->paginate($this->pageSize(25));
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function selectedProjects(): Collection
    {
        return Project::query()
            ->whereIn('id', array_map('intval', $this->selected))
            ->with('descendants')
            ->defaultOrder()
            ->get();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
        $this->finishAction();
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

    public function clearSort(): void
    {
        $this->sortKey = null;
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    /**
     * Right-click on a row: an unselected project becomes the only
     * selection, a selected one keeps the whole selection.
     */
    public function openContextMenu(int $projectId): void
    {
        $this->authorize('manage', Setting::class);

        if (! in_array($projectId, array_map('intval', $this->selected), true)) {
            $this->selected = [(string) $projectId];
        }

        $this->confirmingBulkDelete = false;
        unset($this->selectedProjects);
    }

    public function archive(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('archive', $project);

        $project->status = ProjectStatus::Archived;
        $project->save();

        $this->finishAction();
    }

    public function unarchive(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('archive', $project);

        $project->status = ProjectStatus::Active;
        $project->save();

        $this->finishAction();
    }

    public function startBulkDelete(): void
    {
        $this->authorize('manage', Setting::class);

        abort_if($this->selectedProjects->isEmpty(), 404);

        $this->confirmingBulkDelete = true;
        $this->bulkDeleteConfirmation = '';
        $this->resetErrorBag();
    }

    /**
     * Redmine's ProjectsController#bulk_destroy: sudo mode, then "はい"
     * typed into the box. A selected project inside another selected one
     * goes with its ancestor (deleting a project deletes its subtree).
     */
    public function bulkDelete(): void
    {
        $this->authorize('manage', Setting::class);

        $projects = $this->selectedProjects;
        abort_if($projects->isEmpty(), 404);

        foreach ($projects as $project) {
            $this->authorize('delete', $project);
        }

        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        $this->resetErrorBag();

        if ($this->bulkDeleteConfirmation !== __('はい')) {
            $this->addError('bulkDeleteConfirmation', __('確認のための入力が一致しません。'));

            return;
        }

        $roots = $projects->reject(fn (Project $project) => $projects->contains(
            fn (Project $other) => $other->_lft < $project->_lft && $other->_rgt > $project->_rgt
        ));

        // Each delete renumbers the tree, so every root is reloaded first:
        // the nested set deletes the subtree by its current bounds.
        foreach ($roots as $root) {
            Project::query()->find($root->id)?->delete();
        }

        session()->flash('status', __('削除しました。'));
        $this->finishAction();
    }

    public function cancelBulkDelete(): void
    {
        $this->confirmingBulkDelete = false;
        $this->bulkDeleteConfirmation = '';
        $this->resetErrorBag();
    }

    public function columnValue(Project $project, string $key): string
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
                ProjectStatus::Closed => __('クローズ'),
                ProjectStatus::Archived => __('アーカイブ済み'),
                default => __('アクティブ'),
            },
            'homepage' => (string) $project->homepage,
            'parent_id' => (string) $project->parent?->name,
            'is_public' => $project->is_public ? __('はい') : __('いいえ'),
            'created_at' => \App\Support\Format\DateTimes::dateTime($project->created_at) ?? '',
            'updated_at' => \App\Support\Format\DateTimes::dateTime($project->updated_at) ?? '',
            default => '',
        };
    }

    private function finishAction(): void
    {
        $this->reset('selected', 'confirmingBulkDelete', 'bulkDeleteConfirmation');
        unset($this->projects, $this->selectedProjects);
    }
}; ?>

<div x-data="{ menu: { open: false, x: 0, y: 0 }, showMenu(event, projectId) { const x = event.clientX, y = event.clientY; $wire.openContextMenu(projectId).then(() => { this.menu = { open: true, x: Math.min(x, window.innerWidth - 220), y: Math.min(y, window.innerHeight - 200) }; }); } }"
    x-on:click.window="menu.open = false" x-on:keydown.escape.window="menu.open = false">
    @if (count($selected) > 0)
        @php $selectedProjects = $this->selectedProjects; @endphp
        <div x-show="menu.open" x-cloak x-on:click.stop x-bind:style="`left:${menu.x}px;top:${menu.y}px`" data-context-menu
            class="fixed z-50 w-52 rounded-md border border-neutral-200 bg-white py-1 text-sm shadow-lg">
            @if ($selectedProjects->count() === 1)
                @php $selectedProject = $selectedProjects->first(); @endphp
                @if ($selectedProject->status === \App\Enums\ProjectStatus::Archived)
                    <button type="button" wire:click="unarchive({{ $selectedProject->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ __('アーカイブ解除') }}</button>
                @else
                    <button type="button" wire:click="archive({{ $selectedProject->id }})" wire:confirm="{{ __('本当にプロジェクト「:name」をアーカイブしますか?', ['name' => $selectedProject->name]) }}" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ __('アーカイブ') }}</button>
                @endif
                <a href="{{ route('projects.show', $selectedProject) }}#project-delete" class="block border-t border-neutral-100 px-3 py-1.5 text-danger-bolder hover:bg-danger-subtlest">{{ __('削除') }}</a>
            @else
                <button type="button" wire:click="startBulkDelete" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-danger-bolder hover:bg-danger-subtlest">{{ __('削除') }}</button>
            @endif
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('プロジェクト管理') }}</h1>
        <a href="{{ route('projects.create') }}" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">{{ __('新規プロジェクト') }}</a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-success-subtlest px-4 py-2 text-sm text-success-bolder">{{ session('status') }}</div>
    @endif

    @if ($confirmingBulkDelete)
        <div class="mb-6 rounded-md border border-danger-subtler bg-danger-subtlest p-4 text-sm" data-bulk-delete>
            <h2 class="font-semibold text-danger-boldest">{{ __('確認') }}</h2>
            <p class="mt-1 text-danger-bolder">{{ __('以下のプロジェクト(それらのサブプロジェクトと関連データも含む)を恒久的に削除しようとしています。内容を確認してください。この操作は元に戻せません。') }}</p>
            <ul class="mt-3 space-y-1 text-neutral-800">
                @foreach ($this->selectedProjects as $project)
                    <li wire:key="bulk-delete-{{ $project->id }}">
                        {{ __('プロジェクト') }}: <strong>{{ $project->name }}</strong>
                        @if ($project->descendants->isNotEmpty())
                            <br><span class="text-xs text-neutral-600">{{ __('次のサブプロジェクトを含む: :names', ['names' => $project->descendants->pluck('name')->join(', ')]) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <form wire:submit="bulkDelete" class="mt-3 flex flex-wrap items-end gap-2">
                <div>
                    <label for="bulk-delete-confirmation" class="block text-xs text-danger-bolder">{{ __('削除する場合は確認のため「:yes」と入力してください。', ['yes' => __('はい')]) }}</label>
                    <input id="bulk-delete-confirmation" type="text" wire:model="bulkDeleteConfirmation" class="mt-1 block rounded-md border-neutral-300 text-sm">
                    @error('bulkDeleteConfirmation') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-danger-bolder px-3 py-2 text-sm font-medium text-white hover:bg-danger-subtle">{{ __('削除') }}</button>
                <button type="button" wire:click="cancelBulkDelete" class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50">{{ __('キャンセル') }}</button>
            </form>
        </div>
    @endif

    <div class="mb-4 rounded-md border border-neutral-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">{{ __('絞り込み適用') }}</button>
            <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-700">
                {{ __('表示列:') }}
                @foreach ($this->availableColumns as $columnKey => $columnLabel)
                    <label class="flex items-center gap-1" wire:key="admin-project-column-{{ $columnKey }}">
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
                    <th class="px-4 py-2"></th>
                    @foreach ($this->visibleColumns as $columnKey)
                        <th wire:key="admin-project-heading-{{ $columnKey }}" class="px-4 py-2">
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
                    <tr wire:key="admin-project-row-{{ $project->id }}" x-on:contextmenu.prevent="showMenu($event, {{ $project->id }})"
                        class="{{ in_array((string) $project->id, array_map('strval', $selected), true) ? 'bg-brand-subtlest' : '' }}">
                        <td class="px-4 py-2">
                            <input type="checkbox" wire:model.live="selected" value="{{ $project->id }}" aria-label="{{ $project->name }}" class="rounded border-neutral-300">
                        </td>
                        @foreach ($this->visibleColumns as $columnKey)
                            <td wire:key="admin-project-{{ $project->id }}-{{ $columnKey }}" class="px-4 py-2 align-top"
                                @if ($loop->first && $sortKey === null) style="padding-left: {{ 16 + (int) $project->depth * 16 }}px" @endif>
                                @if ($columnKey === 'name')
                                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-brand-bold hover:underline">{{ $project->name }}</a>
                                    @unless ($project->is_public)
                                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ __('非公開') }}</span>
                                    @endunless
                                @else
                                    {{ $this->columnValue($project, $columnKey) }}
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-2 text-right">
                            <button type="button" x-on:click.stop="showMenu($event, {{ $project->id }})" class="rounded px-2 text-neutral-500 hover:bg-neutral-100" title="{{ __('操作') }}" aria-label="{{ __('操作') }}">…</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($this->visibleColumns) + 2 }}" class="px-4 py-6 text-sm text-neutral-500">{{ __('プロジェクトがありません。') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->projects->perPage()" :total="$this->projects->total()" /></div>
        {{ $this->projects->links() }}
    </div>
</div>
