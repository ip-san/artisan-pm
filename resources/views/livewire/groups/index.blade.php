<?php

use App\Models\Group;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Group::class);
    }

    #[Computed]
    public function groups(): Collection
    {
        return Group::query()->withCount('users')->orderBy('name')->get();
    }

    public function delete(int $groupId): void
    {
        $group = Group::findOrFail($groupId);
        $this->authorize('delete', $group);
        $group->delete();

        unset($this->groups);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">グループ管理</h1>
        <a href="{{ route('groups.create') }}"
            class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            新規グループ
        </a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->groups as $group)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $group->name }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $group->users_count }} 人</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('groups.edit', $group) }}" class="text-sm text-brand-bold hover:underline">編集</a>
                    <button wire:click="delete({{ $group->id }})" wire:confirm="このグループを削除しますか?"
                        class="text-sm text-danger-bolder hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">グループがありません。</li>
        @endforelse
    </ul>
</div>
