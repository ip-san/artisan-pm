<?php

use App\Enums\RoleBuiltin;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /** @var array<int, array<string, bool>> role id => permission key => checked */
    public array $matrix = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);

        foreach ($this->roles as $role) {
            $this->matrix[$role->id] = [];

            foreach ($this->permissionKeysFor($role) as $key) {
                $this->matrix[$role->id][$key] = $role->hasPermission($key);
            }
        }
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderBy('position')->get();
    }

    /**
     * Every registered permission, in registration order — used as the
     * row order for the matrix so it stays stable across roles even
     * though each role only shows checkboxes for the subset it may hold.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function allPermissionKeys(): array
    {
        return array_keys(app(PermissionRegistry::class)->all());
    }

    /**
     * @return array<int, string>
     */
    private function permissionKeysFor(Role $role): array
    {
        $registry = app(PermissionRegistry::class);
        $isAnonymous = $role->builtin === RoleBuiltin::Anonymous;
        $isNonMember = $role->builtin === RoleBuiltin::NonMember;

        return array_keys($registry->assignableTo($isAnonymous, $isNonMember));
    }

    public function save(): void
    {
        $this->authorize('viewAny', Role::class);

        foreach ($this->roles as $role) {
            $allowedKeys = $this->permissionKeysFor($role);
            $checked = $this->matrix[$role->id] ?? [];

            $permissions = array_values(array_filter(
                $allowedKeys,
                fn (string $key) => $checked[$key] ?? false
            ));

            $role->update(['permissions' => $permissions]);
        }

        unset($this->roles);
        session()->flash('status', '権限を保存しました。');
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">権限レポート</h1>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <form wire:submit="save">
        <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50">
                        <th class="px-3 py-2 text-left font-medium text-gray-700">権限</th>
                        @foreach ($this->roles as $role)
                            <th class="px-3 py-2 text-center font-medium text-gray-700">{{ $role->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->allPermissionKeys as $permissionKey)
                        <tr class="border-b border-gray-100">
                            <td class="px-3 py-2 text-gray-900">{{ $permissionKey }}</td>
                            @foreach ($this->roles as $role)
                                <td class="px-3 py-2 text-center">
                                    @if (array_key_exists($permissionKey, $this->matrix[$role->id]))
                                        <input type="checkbox" wire:model="matrix.{{ $role->id }}.{{ $permissionKey }}" class="rounded border-gray-300">
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('roles.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
