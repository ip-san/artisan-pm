<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\UserFilterFieldRegistry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

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
        unset($this->users, $this->selectedUsers);
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
        $chosen = array_values(array_intersect(array_keys(UserFilterFieldRegistry::columns()), $this->columns));

        return $chosen === [] ? UserFilterFieldRegistry::defaultColumns() : $chosen;
    }

    public function columnValue(User $user, string $key): string
    {
        return match ($key) {
            'name' => $user->name,
            'login' => $user->login,
            'email' => $user->email,
            'is_admin' => $user->is_admin ? '管理者' : '',
            'status' => match ($user->status) {
                UserStatus::Locked => 'ロック中',
                UserStatus::Registered => '承認待ち',
                default => '有効',
            },
            'auth_source_id' => $user->authSource !== null ? 'LDAP: '.$user->authSource->name : '',
            'created_at' => $user->created_at?->format('Y-m-d H:i') ?? '',
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i') ?? '',
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
        $users = $this->users;

        return response()->streamDownload(function () use ($columns, $users): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map(fn ($key) => UserFilterFieldRegistry::columns()[$key], $columns));

            foreach ($users as $user) {
                fputcsv($handle, array_map(fn ($key) => $this->columnValue($user, $key), $columns));
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
        return $this->users->whereIn('id', array_map('intval', $this->selected))->values();
    }

    /**
     * Right-click on a row: an unselected user becomes the only selection,
     * a selected one keeps the whole selection (Redmine's context menu).
     */
    public function openContextMenu(int $userId): void
    {
        $this->authorize('viewAny', User::class);

        abort_unless($this->users->contains('id', $userId), 404);

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
    public function users(): Collection
    {
        $query = User::query()->with(['authSource', 'groups'])
            ->where('status', '!=', UserStatus::Deleted);

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        if ($this->sortKey !== null && in_array($this->sortKey, array_keys(UserFilterFieldRegistry::columns()), true)) {
            $query = $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
        }

        return $query->orderBy('name')->get();
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
            class="fixed z-50 w-52 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg">
            @if ($selectedUsers->count() === 1)
                <a href="{{ route('users.edit', $selectedUsers->first()) }}" class="block px-3 py-1.5 text-gray-700 hover:bg-gray-100">編集</a>
            @endif
            @if ($selectedUsers->every(fn ($user) => $user->status === \App\Enums\UserStatus::Locked))
                <button type="button" wire:click="bulkSetLocked(false)" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">ロック解除</button>
            @else
                <button type="button" wire:click="bulkSetLocked(true)" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">ロック</button>
            @endif
            @if ($this->groups->isNotEmpty())
                <div class="group relative">
                    <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-gray-700 group-hover:bg-gray-100">グループに追加 <span class="text-gray-400">›</span></span>
                    <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg group-hover:block">
                        @foreach ($this->groups as $group)
                            <button type="button" wire:key="context-add-{{ $group->id }}" wire:click="addToGroup({{ $group->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">{{ $group->name }}</button>
                        @endforeach
                    </div>
                </div>
                @if ($commonGroupIds->isNotEmpty())
                    <div class="group relative">
                        <span class="flex cursor-default items-center justify-between px-3 py-1.5 text-gray-700 group-hover:bg-gray-100">グループから外す <span class="text-gray-400">›</span></span>
                        <div class="absolute left-full top-0 hidden max-h-72 w-44 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg group-hover:block">
                            @foreach ($this->groups->whereIn('id', $commonGroupIds->all()) as $group)
                                <button type="button" wire:key="context-remove-{{ $group->id }}" wire:click="removeFromGroup({{ $group->id }})" x-on:click="menu.open = false" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-100">{{ $group->name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
            <button type="button" wire:click="bulkDelete" wire:confirm="選択した{{ $selectedUsers->count() }}人のユーザーを削除します。この操作は取り消せません。よろしいですか?" x-on:click="menu.open = false" class="block w-full border-t border-gray-100 px-3 py-1.5 text-left text-red-700 hover:bg-red-50">削除</button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">ユーザー管理</h1>
        <div class="flex gap-2">
            <a href="{{ route('users.import') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">CSVインポート</a>
            <a href="{{ route('users.create') }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規ユーザー
            </a>
        </div>
    </div>

    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">絞り込み適用</button>
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
                表示列:
                @foreach (\App\Support\Query\UserFilterFieldRegistry::columns() as $columnKey => $columnLabel)
                    <label class="flex items-center gap-1" wire:key="user-column-{{ $columnKey }}">
                        <input type="checkbox" wire:model.live="columns" value="{{ $columnKey }}" class="rounded border-gray-300">
                        {{ $columnLabel }}
                    </label>
                @endforeach
            </div>
            <button wire:click="exportCsv" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">CSVエクスポート</button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2"></th>
                    @foreach ($this->visibleColumns as $columnKey)
                        <th wire:key="user-heading-{{ $columnKey }}" class="px-4 py-2">
                            <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-gray-900">
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
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->users as $user)
                    <tr wire:key="user-row-{{ $user->id }}" x-on:contextmenu.prevent="showMenu($event, {{ $user->id }})"
                        class="{{ in_array((string) $user->id, array_map('strval', $selected), true) ? 'bg-indigo-50' : '' }}">
                        <td class="px-4 py-2">
                            <input type="checkbox" wire:model.live="selected" value="{{ $user->id }}" class="rounded border-gray-300">
                        </td>
                        @foreach ($this->visibleColumns as $columnKey)
                            <td wire:key="user-{{ $user->id }}-{{ $columnKey }}" class="px-4 py-2">
                                @if ($columnKey === 'name')
                                    <a href="{{ route('users.show', $user) }}" class="font-medium text-gray-900 hover:underline">{{ $user->name }}</a>
                                @elseif ($columnKey === 'status' && $user->status === \App\Enums\UserStatus::Locked)
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">{{ $this->columnValue($user, $columnKey) }}</span>
                                @elseif ($columnKey === 'status' && $user->status === \App\Enums\UserStatus::Registered)
                                    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">{{ $this->columnValue($user, $columnKey) }}</span>
                                @elseif ($columnKey === 'is_admin' && $user->is_admin)
                                    <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-700">{{ $this->columnValue($user, $columnKey) }}</span>
                                @else
                                    {{ $this->columnValue($user, $columnKey) }}
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-3">
                                <a href="{{ route('users.edit', $user) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                                @if ($user->status === \App\Enums\UserStatus::Registered)
                                    <button wire:click="approve({{ $user->id }})" wire:confirm="このユーザーを承認しますか?"
                                        class="text-sm text-green-600 hover:underline">
                                        承認
                                    </button>
                                @endif
                                @unless ($user->is(auth()->user()))
                                    <button wire:click="toggleLock({{ $user->id }})"
                                        wire:confirm="{{ $user->status === \App\Enums\UserStatus::Locked ? 'このユーザーのロックを解除しますか?' : 'このユーザーをロックしますか?' }}"
                                        class="text-sm {{ $user->status === \App\Enums\UserStatus::Locked ? 'text-green-600' : 'text-red-600' }} hover:underline">
                                        {{ $user->status === \App\Enums\UserStatus::Locked ? 'ロック解除' : 'ロック' }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($this->visibleColumns) + 2 }}" class="px-4 py-6 text-center text-gray-500">該当するユーザーがいません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
