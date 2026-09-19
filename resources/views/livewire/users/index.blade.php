<?php

use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /** @var array<int, string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
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
        return User::query()->with(['authSource', 'groups'])
            ->where('status', '!=', UserStatus::Deleted)
            ->orderBy('name')->get();
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
        <a href="{{ route('users.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規ユーザー
        </a>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @foreach ($this->users as $user)
            <li wire:key="user-row-{{ $user->id }}" x-on:contextmenu.prevent="showMenu($event, {{ $user->id }})"
                class="flex items-center justify-between px-4 py-3 {{ in_array((string) $user->id, array_map('strval', $selected), true) ? 'bg-indigo-50' : '' }}">
                <div>
                    <input type="checkbox" wire:model.live="selected" value="{{ $user->id }}" class="mr-3 rounded border-gray-300">
                    <a href="{{ route('users.show', $user) }}" class="font-medium text-gray-900 hover:underline">{{ $user->name }}</a>
                    <span class="ml-2 text-xs text-gray-500">{{ $user->email }}</span>
                    @if ($user->is_admin)
                        <span class="ml-2 rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-700">管理者</span>
                    @endif
                    @if ($user->status === \App\Enums\UserStatus::Locked)
                        <span class="ml-2 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">ロック中</span>
                    @elseif ($user->status === \App\Enums\UserStatus::Registered)
                        <span class="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">承認待ち</span>
                    @endif
                    @if ($user->authSource)
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">LDAP: {{ $user->authSource->name }}</span>
                    @endif
                </div>
                <div class="flex gap-3">
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
            </li>
        @endforeach
    </ul>
</div>
