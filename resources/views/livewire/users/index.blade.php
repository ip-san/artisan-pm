<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Concerns\ReordersColumns;
use App\Concerns\SelectsPageSize;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\Query as SavedQuery;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Support\Export\CsvCell;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\UserFilterFieldRegistry;
use App\Support\Query\CustomFieldFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;
    use ReordersColumns;
    use SelectsPageSize;
    use WithPagination;

    public string $newQueryName = '';

    public bool $showSaveForm = false;

    /**
     * Set by editQuery() while the save form is prefilled with an
     * existing query's settings, for saveQuery() to update in place
     * instead of creating a new one (A15-07b, mirroring the issue
     * list's own editQuery()).
     */
    public ?int $editingQueryId = null;

    /** @var array<int, string> */
    public array $selected = [];

    /** @var array<int, string> */
    #[Url]
    public array $columns = [];

    #[Url]
    public ?string $sortKey = null;

    #[Url]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        if ($this->columns === []) {
            $this->columns = UserFilterFieldRegistry::defaultColumns();
        }
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(UserFilterFieldRegistry::all());
    }

    public function applyFilters(): void
    {
        $this->selected = [];
        $this->resetPage();
        unset($this->users, $this->selectedUsers);
    }

    /**
     * Saved user queries (Redmine's UserQuery): administrators only, and
     * every administrator sees all of them.
     *
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleGlobally(QueryType::User, auth()->user());
    }

    public function saveQuery(): void
    {
        $this->authorize('viewAny', User::class);

        $editing = $this->editingQueryId !== null ? SavedQuery::findOrFail($this->editingQueryId) : null;

        if ($editing !== null) {
            $this->authorize('update', $editing);
        }

        $data = $this->validate(['newQueryName' => ['required', 'string', 'max:255']]);

        $attributes = [
            'name' => $data['newQueryName'],
            'project_id' => null,
            'visibility' => QueryVisibility::Private->value,
            'filters' => $this->builtFilters(),
            'column_names' => $this->visibleColumns,
            'sort_criteria' => $this->sortKey !== null ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => null,
        ];

        if ($editing !== null) {
            $editing->update($attributes);
        } else {
            SavedQuery::create([...$attributes, 'type' => QueryType::User->value, 'user_id' => auth()->id()]);
        }

        $this->reset(['newQueryName', 'editingQueryId', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', $editing !== null ? __('クエリを更新しました。') : __('クエリを保存しました。'));
    }

    /**
     * Opens the save form prefilled with an existing saved query's
     * settings, for saveQuery() to update in place — Redmine's
     * QueriesController#edit, mirrored from the issue list's own
     * editQuery() (A15-07b). Every saved user query is private
     * (Redmine's UserQuery, admin-only), so there is no visibility/roles
     * state to restore here, unlike the issue/time-entry/project lists.
     */
    public function editQuery(int $queryId): void
    {
        $this->authorize('viewAny', User::class);

        $query = SavedQuery::findOrFail($queryId);
        $this->authorize('update', $query);

        $this->loadQuery($queryId);
        $this->editingQueryId = $query->id;
        $this->newQueryName = $query->name;
        $this->showSaveForm = true;
    }

    public function cancelEditQuery(): void
    {
        $this->reset(['newQueryName', 'editingQueryId', 'showSaveForm']);
    }

    public function deleteQuery(int $queryId): void
    {
        $this->authorize('viewAny', User::class);

        $query = SavedQuery::findOrFail($queryId);
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
        $this->authorize('viewAny', User::class);

        $query = SavedQuery::query()->where('type', QueryType::User->value)->whereNull('project_id')->find($queryId);

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
        $this->sortKey = null;
        $this->sortDirection = 'asc';

        if (isset($query->sort_criteria[0])) {
            [$this->sortKey, $this->sortDirection] = $query->sort_criteria[0];
        }

        $this->selected = [];
        $this->resetPage();
        unset($this->users, $this->selectedUsers, $this->visibleColumns);
    }

    public function sortBy(string $key): void
    {
        if ($this->sortKey === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * The chosen columns in the list's own order, limited to real ones.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function visibleColumns(): array
    {
        $chosen = array_values(array_intersect($this->columns, array_keys(UserFilterFieldRegistry::columns())));

        return $chosen === [] ? UserFilterFieldRegistry::defaultColumns() : $chosen;
    }

    public function columnValue(User $user, string $key): string
    {
        if (str_starts_with($key, 'cf_')) {
            return $user->customFieldValues
                ->where('custom_field_id', (int) substr($key, 3))
                ->map(fn ($value) => $value->displayValue())
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->join(', ');
        }

        return match ($key) {
            'name' => $user->name,
            'login' => $user->login,
            'firstname' => (string) $user->firstname,
            'lastname' => (string) $user->lastname,
            'email' => $user->email,
            'is_admin' => $user->is_admin ? __('管理者') : '',
            'status' => match ($user->status) {
                UserStatus::Locked => __('ロック中'),
                UserStatus::Registered => __('承認待ち'),
                default => __('有効'),
            },
            'auth_source_id' => $user->authSource !== null ? 'LDAP: '.$user->authSource->name : '',
            'created_at' => \App\Support\Format\DateTimes::dateTime($user->created_at) ?? '',
            'last_login_at' => \App\Support\Format\DateTimes::dateTime($user->last_login_at) ?? '',
            default => '',
        };
    }

    /**
     * The filtered list as a CSV of the chosen columns (Redmine's users.csv),
     * UTF-8 with a byte-order mark so Excel reads it correctly.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $columns = $this->visibleColumns;
        $users = $this->filteredUsersQuery()->get();

        return response()->streamDownload(function () use ($columns, $users): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, CsvCell::row(array_map(fn ($key) => UserFilterFieldRegistry::columns()[$key], $columns)));

            foreach ($users as $user) {
                fputcsv($handle, CsvCell::row(array_map(fn ($key) => $this->columnValue($user, $key), $columns)));
            }

            fclose($handle);
        }, 'users.csv');
    }

    /**
     * @return Collection<int, Group>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Group::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function selectedUsers(): Collection
    {
        if ($this->selected === []) {
            return collect();
        }

        return $this->filteredUsersQuery()->whereIn('users.id', array_map('intval', $this->selected))->get();
    }

    /**
     * Right-click on a row: an unselected user becomes the only selection,
     * a selected one keeps the whole selection (Redmine's context menu).
     */
    public function openContextMenu(int $userId): void
    {
        $this->authorize('viewAny', User::class);

        abort_unless($this->filteredUsersQuery()->whereKey($userId)->exists(), 404);

        if (! in_array($userId, array_map('intval', $this->selected), true)) {
            $this->selected = [(string) $userId];
        }

        unset($this->selectedUsers);
    }

    /**
     * Locks or unlocks the selection. The signed-in admin is left out so
     * nobody can lock themselves out (same guard as toggleLock).
     */
    public function bulkSetLocked(bool $locked): void
    {
        $this->authorize('update', $this->selectedUsers->first() ?? abort(404));

        $status = $locked ? UserStatus::Locked : UserStatus::Active;

        foreach ($this->selectedUsers->reject(fn (User $user) => $user->is(auth()->user())) as $user) {
            $user->update(['status' => $status->value]);
        }

        $this->finishBulkAction();
    }

    /**
     * Deletes (anonymizes) the selection, except the signed-in admin and
     * any administrator whose removal would leave no other active admin.
     */
    public function bulkDelete(): void
    {
        $this->authorize('update', $this->selectedUsers->first() ?? abort(404));

        foreach ($this->selectedUsers->reject(fn (User $user) => $user->is(auth()->user())) as $user) {
            if ($user->is_admin && ! User::query()->where('is_admin', true)->where('status', UserStatus::Active)->whereKeyNot($user->id)->exists()) {
                continue;
            }

            app(AccountDeletionService::class)->delete($user);
        }

        $this->finishBulkAction();
    }

    public function addToGroup(int $groupId): void
    {
        $this->authorize('update', $this->selectedUsers->first() ?? abort(404));

        Group::query()->findOrFail($groupId)->users()->syncWithoutDetaching($this->selectedUsers->pluck('id')->all());

        $this->finishBulkAction();
    }

    public function removeFromGroup(int $groupId): void
    {
        $this->authorize('update', $this->selectedUsers->first() ?? abort(404));

        Group::query()->findOrFail($groupId)->users()->detach($this->selectedUsers->pluck('id')->all());

        $this->finishBulkAction();
    }

    private function finishBulkAction(): void
    {
        $this->reset('selected');
        unset($this->users, $this->selectedUsers);
    }

    /**
     * Excludes self-deleted accounts (UserStatus::Deleted) — Redmine
     * hard-deletes the row on account deletion, so it simply vanishes from
     * this list; this app anonymizes the row in place instead (see
     * App\Services\AccountDeletionService), so the same "gone from the
     * admin list" outcome needs an explicit filter here.
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return $this->filteredUsersQuery()->paginate($this->pageSize());
    }

    /**
     * @return Builder<User>
     */
    private function filteredUsersQuery(): Builder
    {
        $query = User::query()->with(['authSource', 'groups', 'customFieldValues.customField'])
            ->where('status', '!=', UserStatus::Deleted);

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        if ($this->sortKey !== null && array_key_exists($this->sortKey, UserFilterFieldRegistry::columns())) {
            $field = UserFilterFieldRegistry::customFieldFor($this->sortKey);
            $query = $field !== null
                ? (new CustomFieldFilter($field))->applySort($query, $this->sortDirection === 'desc' ? 'desc' : 'asc')
                : $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
        }

        return $query->orderBy('name')->orderBy('users.id');
    }

    public function toggleLock(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('update', $user);

        // Can't lock your own account through this screen — that would
        // leave nobody able to unlock it without direct DB access.
        abort_if($user->is(auth()->user()), 403);

        $user->update(['status' => $user->status === UserStatus::Locked ? UserStatus::Active->value : UserStatus::Locked->value]);

        unset($this->users);
    }

    /**
     * Activates a self-registered account awaiting manual approval —
     * matches Redmine's User#activate for a Setting.self_registration
     * 'manual' signup.
     */
    public function approve(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('update', $user);

        abort_unless($user->status === UserStatus::Registered, 403);

        $user->update(['status' => UserStatus::Active->value]);

        unset($this->users);
    }
}; ?>

<div x-data="{ menu: { open: false, x: 0, y: 0 }, showMenu(event, userId) { const x = event.clientX, y = event.clientY; $wire.openContextMenu(userId).then(() => { this.menu = { open: true, x: Math.min(x, window.innerWidth - 220), y: Math.min(y, window.innerHeight - 260) }; }); } }"
    x-on:click.window="menu.open = false" x-on:keydown.escape.window="menu.open = false">
    @if (count($selected) > 0)
        @php
            $selectedUsers = $this->selectedUsers;
            $commonGroupIds = $selectedUsers->map(fn ($user) => $user->groups->pluck('id'))->reduce(fn ($carry, $ids) => $carry === null ? $ids : $carry->intersect($ids));
        @endphp
        <div x-show="menu.open" x-cloak x-on:click.stop x-bind:style="`left:${menu.x}px;top:${menu.y}px`" data-context-menu
            class="fixed z-50 w-52 rounded-md border border-neutral-200 bg-surface py-1 text-sm shadow-lg">
            @if ($selectedUsers->count() === 1)
                <a href="{{ route('users.edit', $selectedUsers->first()) }}" class="block px-3 py-1.5 text-neutral-700 hover:bg-neutral-100">{{ __('編集') }}</a>
            @endif
            @if ($selectedUsers->every(fn ($user) => $user->status === \App\Enums\UserStatus::Locked))
                <button type="button" wire:click="bulkSetLocked(false)" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ __('ロック解除') }}</button>
            @else
                <button type="button" wire:click="bulkSetLocked(true)" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ __('ロック') }}</button>
            @endif
            @if ($this->groups->isNotEmpty())
                <div class="group relative">
                    <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-neutral-700 group-hover:bg-neutral-100">{{ __('グループに追加') }} <span class="text-neutral-400">›</span></span>
                    <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-neutral-200 bg-surface py-1 shadow-lg group-hover:block">
                        @foreach ($this->groups as $group)
                            <button type="button" wire:key="context-add-{{ $group->id }}" wire:click="addToGroup({{ $group->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ $group->name }}</button>
                        @endforeach
                    </div>
                </div>
                @if ($commonGroupIds->isNotEmpty())
                    <div class="group relative">
                        <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-neutral-700 group-hover:bg-neutral-100">{{ __('グループから外す') }} <span class="text-neutral-400">›</span></span>
                        <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-neutral-200 bg-surface py-1 shadow-lg group-hover:block">
                            @foreach ($this->groups->whereIn('id', $commonGroupIds->all()) as $group)
                                <button type="button" wire:key="context-remove-{{ $group->id }}" wire:click="removeFromGroup({{ $group->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">{{ $group->name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
            <button type="button" wire:click="bulkDelete" wire:confirm="{{ __('選択した:count人のユーザーを削除します。この操作は取り消せません。よろしいですか?', ['count' => $selectedUsers->count()]) }}" x-on:click="menu.open = false" class="block w-full border-t border-neutral-100 px-3 py-1.5 text-left text-danger-bolder hover:bg-danger-subtlest">{{ __('削除') }}</button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('ユーザー管理') }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('users.import') }}" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">{{ __('CSVインポート') }}</a>
            <a href="{{ route('users.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('新規ユーザー') }}
            </a>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-neutral-500">{{ __('保存済みクエリ:') }}</span>
        @forelse ($this->savedQueries as $savedQuery)
            <x-saved-query-pill :query="$savedQuery" />
        @empty
            <span class="text-neutral-400">{{ __('なし') }}</span>
        @endforelse
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-surface p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">{{ __('絞り込み適用') }}</button>
            <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-700">
                {{ __('表示列:') }}
                @foreach (\App\Support\Query\UserFilterFieldRegistry::columns() as $columnKey => $columnLabel)
                    <label class="flex items-center gap-1" wire:key="user-column-{{ $columnKey }}">
                        <input type="checkbox" wire:model.live="columns" value="{{ $columnKey }}" class="rounded border-neutral-300">
                        {{ $columnLabel }}
                    </label>
                @endforeach
            </div>
            <button wire:click="exportCsv" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">{{ __('CSVエクスポート') }}</button>
            <button wire:click="$toggle('showSaveForm')" class="text-sm text-brand-bold hover:underline">{{ __('クエリを保存') }}</button>
        </div>

        @if ($showSaveForm)
            <form wire:submit="saveQuery" class="mt-3 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-3">
                <input type="text" wire:model="newQueryName" placeholder="{{ __('クエリ名') }}" class="rounded-md border-neutral-300 text-sm">
                <span class="text-xs text-neutral-500">{{ __('(すべての管理者に表示されます)') }}</span>
                <button type="submit" class="rounded-md bg-brand-bold px-3 py-1.5 text-sm font-medium text-white hover:bg-brand">{{ $editingQueryId !== null ? __('更新') : __('保存') }}</button>
                @if ($editingQueryId !== null)
                    <button type="button" wire:click="cancelEditQuery" class="text-sm text-neutral-500 hover:underline">{{ __('キャンセル') }}</button>
                @endif
                @error('newQueryName') <span class="text-sm text-danger-bolder">{{ $message }}</span> @enderror
            </form>
        @endif

        <div class="mt-3">
            <x-column-order :columns="$this->visibleColumns" :labels="\App\Support\Query\UserFilterFieldRegistry::columns()" />
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-neutral-200 bg-surface">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
                <tr>
                    <th class="px-4 py-2"></th>
                    @foreach ($this->visibleColumns as $columnKey)
                        <th wire:key="user-heading-{{ $columnKey }}" class="px-4 py-2">
                            <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-neutral-900">
                                {{ \App\Support\Query\UserFilterFieldRegistry::columns()[$columnKey] }}
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
                @forelse ($this->users as $user)
                    <tr wire:key="user-row-{{ $user->id }}" x-on:contextmenu.prevent="showMenu($event, {{ $user->id }})"
                        class="{{ in_array((string) $user->id, array_map('strval', $selected), true) ? 'bg-brand-subtlest' : '' }}">
                        <td class="px-4 py-2">
                            <input type="checkbox" wire:model.live="selected" value="{{ $user->id }}" class="rounded border-neutral-300">
                        </td>
                        @foreach ($this->visibleColumns as $columnKey)
                            <td wire:key="user-{{ $user->id }}-{{ $columnKey }}" class="px-4 py-2">
                                @if ($columnKey === 'name')
                                    <a href="{{ route('users.show', $user) }}" class="font-medium text-neutral-900 hover:underline">{{ $user->name }}</a>
                                @elseif ($columnKey === 'status' && $user->status === \App\Enums\UserStatus::Locked)
                                    <span class="rounded bg-danger-subtlest px-1.5 py-0.5 text-xs text-danger-bolder">{{ $this->columnValue($user, $columnKey) }}</span>
                                @elseif ($columnKey === 'status' && $user->status === \App\Enums\UserStatus::Registered)
                                    <span class="rounded bg-warning-subtlest px-1.5 py-0.5 text-xs text-warning-bold">{{ $this->columnValue($user, $columnKey) }}</span>
                                @elseif ($columnKey === 'is_admin' && $user->is_admin)
                                    <span class="rounded bg-brand-subtlest px-1.5 py-0.5 text-xs text-brand-bolder">{{ $this->columnValue($user, $columnKey) }}</span>
                                @else
                                    {{ $this->columnValue($user, $columnKey) }}
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-3">
                                <a href="{{ route('users.edit', $user) }}" class="text-sm text-brand-bold hover:underline">{{ __('編集') }}</a>
                                @if ($user->status === \App\Enums\UserStatus::Registered)
                                    <button wire:click="approve({{ $user->id }})" wire:confirm="{{ __('このユーザーを承認しますか?') }}"
                                        class="text-sm text-success-bold hover:underline">
                                        {{ __('承認') }}
                                    </button>
                                @endif
                                @unless ($user->is(auth()->user()))
                                    <button wire:click="toggleLock({{ $user->id }})"
                                        wire:confirm="{{ $user->status === \App\Enums\UserStatus::Locked ? __('このユーザーのロックを解除しますか?') : __('このユーザーをロックしますか?') }}"
                                        class="text-sm {{ $user->status === \App\Enums\UserStatus::Locked ? 'text-success-bold' : 'text-danger-bolder' }} hover:underline">
                                        {{ $user->status === \App\Enums\UserStatus::Locked ? __('ロック解除') : __('ロック') }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($this->visibleColumns) + 2 }}" class="px-4 py-6 text-center text-neutral-500">{{ __('該当するユーザーがいません。') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2 flex justify-end"><x-per-page-select :selected="$this->users->perPage()" :total="$this->users->total()" /></div>
    {{ $this->users->links() }}
</div>
