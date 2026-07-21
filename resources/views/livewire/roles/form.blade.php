<?php

use App\Enums\RoleBuiltin;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Role $role = null;

    public string $name = '';

    /** @var array<string> */
    public array $permissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->permissions = $role->permissionKeys();
        } else {
            $this->authorize('create', Role::class);
        }
    }

    /**
     * @return array<string>
     */
    public function getAvailablePermissionsProperty(): array
    {
        $registry = app(PermissionRegistry::class);

        $isAnonymous = $this->role?->builtin === RoleBuiltin::Anonymous;
        $isNonMember = $this->role?->builtin === RoleBuiltin::NonMember;

        return array_keys($registry->assignableTo($isAnonymous, $isNonMember));
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->role?->id)],
        ]);

        $data['permissions'] = array_values(array_intersect($this->permissions, $this->availablePermissions));

        if ($this->role) {
            $this->role->update($data);
        } else {
            $data['position'] = Role::query()->max('position') + 1;
            Role::create($data);
        }

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
