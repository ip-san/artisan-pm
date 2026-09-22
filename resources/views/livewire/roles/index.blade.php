<?php

use App\Models\Role;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderBy('position')->get();
    }

    public function delete(int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $this->authorize('delete', $role);
        $role->delete();

        unset($this->roles);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">ロール管理</h1>
        <div class="flex gap-2">
            <a href="{{ route('roles.report') }}"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                権限レポート
            </a>
            <a href="{{ route('roles.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                新規ロール
            </a>
        </div>
    </div>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @foreach ($this->roles as $role)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $role->name }}</span>
                    @if ($role->builtin)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ $role->builtin->value }}</span>
                    @endif
                    <span class="ml-2 text-xs text-neutral-500">{{ count($role->permissionKeys()) }} 権限</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('roles.edit', $role) }}" class="text-sm text-brand-bold hover:underline">編集</a>
                    <a href="{{ route('roles.create') }}?copy_from={{ $role->id }}" class="text-sm text-brand-bold hover:underline">コピー</a>
                    <button wire:click="delete({{ $role->id }})" wire:confirm="このロールを削除しますか?"
                        class="text-sm text-danger-bolder hover:underline">削除</button>
                </div>
            </li>
        @endforeach
    </ul>
</div>
