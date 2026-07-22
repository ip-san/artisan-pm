<?php

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Role $role = null;

    public string $name = '';

    /** @var array<string> */
    public array $permissions = [];

    public string $issuesVisibility = 'all';

    public string $timeEntriesVisibility = 'all';

    public bool $assignable = true;

    public bool $allRolesManaged = true;

    /** @var array<int> */
    public array $managedRoleIds = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->permissions = $role->permissionKeys();
            $this->issuesVisibility = $role->issues_visibility->value;
            $this->timeEntriesVisibility = $role->time_entries_visibility->value;
            $this->assignable = $role->assignable;
            $this->allRolesManaged = $role->all_roles_managed;
            $this->managedRoleIds = $role->managedRoles->pluck('id')->all();
        } else {
            $this->authorize('create', Role::class);

            $this->prefillFromCopySource();
        }
    }

    /**
     * ?copy_from=<id> seeds a new role's name/permissions from an existing
     * one — the name gets a distinct suffix so it doesn't collide with the
     * source's own unique name if left unedited, and builtin is never
     * copied (this form never sets it at all, even for a fresh role).
     */
    private function prefillFromCopySource(): void
    {
        $sourceId = request()->integer('copy_from');

        if ($sourceId === 0) {
            return;
        }

        $source = Role::find($sourceId);

        if ($source === null) {
            return;
        }

        $this->name = "{$source->name} のコピー";
        $this->permissions = $source->permissionKeys();
        $this->issuesVisibility = $source->issues_visibility->value;
        $this->timeEntriesVisibility = $source->time_entries_visibility->value;
        $this->assignable = $source->assignable;
        $this->allRolesManaged = $source->all_roles_managed;
        $this->managedRoleIds = $source->managedRoles->pluck('id')->all();
    }

    /**
     * @return array<string>
     */
    #[Computed]
    public function availablePermissions(): array
    {
        $registry = app(PermissionRegistry::class);

        $isAnonymous = $this->role?->builtin === RoleBuiltin::Anonymous;
        $isNonMember = $this->role?->builtin === RoleBuiltin::NonMember;

        return array_keys($registry->assignableTo($isAnonymous, $isNonMember));
    }

    /**
     * Every other custom (non-builtin) role — candidates for "管理可能
     * ロール" when this role doesn't manage all of them. Matches Redmine's
     * Role.givable scope.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function otherGivableRoles(): Collection
    {
        return Role::query()
            ->givable()
            ->when($this->role, fn ($query) => $query->whereKeyNot($this->role->id))
            ->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->role?->id)],
            'issuesVisibility' => ['required', Rule::enum(IssueVisibility::class)],
            'timeEntriesVisibility' => ['required', Rule::enum(TimeEntryVisibility::class)],
            'managedRoleIds' => ['array'],
            'managedRoleIds.*' => [Rule::in($this->otherGivableRoles->pluck('id')->all())],
        ]);

        $data['permissions'] = array_values(array_intersect($this->permissions, $this->availablePermissions));
        $data['issues_visibility'] = $data['issuesVisibility'];
        $data['time_entries_visibility'] = $data['timeEntriesVisibility'];
        $data['assignable'] = $this->assignable;
        $data['all_roles_managed'] = $this->allRolesManaged;
        unset($data['issuesVisibility'], $data['timeEntriesVisibility'], $data['managedRoleIds']);

        if ($this->role) {
            $this->role->update($data);
        } else {
            $data['position'] = Role::query()->max('position') + 1;
            $this->role = Role::create($data);
        }

        // Only meaningful when all_roles_managed is off, but kept in sync
        // regardless so toggling it back on later doesn't resurrect a
        // stale subset from before.
        $this->role->managedRoles()->sync($data['all_roles_managed'] ? [] : $this->managedRoleIds);

        $this->redirect(route('roles.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $role ? 'ロールを編集' : '新規ロール' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">課題の閲覧範囲</label>
            <select wire:model="issuesVisibility" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="all">すべての課題</option>
                <option value="default">デフォルト</option>
                <option value="own">自分が作成または担当する課題のみ</option>
            </select>
            @error('issuesVisibility') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">工数の閲覧範囲</label>
            <select wire:model="timeEntriesVisibility" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="all">すべての工数</option>
                <option value="default">デフォルト</option>
                <option value="own">自分の工数のみ</option>
            </select>
            @error('timeEntriesVisibility') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="assignable" class="rounded border-gray-300">
            このロールを持つメンバーを課題の担当者として選択可能にする
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model.live="allRolesManaged" class="rounded border-gray-300">
            このロールを持つメンバーはプロジェクトメンバーのすべてのロールを管理できる
        </label>

        @unless ($allRolesManaged)
            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">管理可能ロール(メンバー管理画面で割当/削除できるロール)</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->otherGivableRoles as $candidate)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="managedRoleIds" value="{{ $candidate->id }}" class="rounded border-gray-300">
                            {{ $candidate->name }}
                        </label>
                    @endforeach
                </div>
                @error('managedRoleIds') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">権限</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($this->availablePermissions as $permission)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="permissions" value="{{ $permission }}" class="rounded border-gray-300">
                        {{ $permission }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('roles.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
