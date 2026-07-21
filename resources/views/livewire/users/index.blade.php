<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    #[Computed]
    public function users(): Collection
    {
        return User::query()->with('authSource')->orderBy('name')->get();
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
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">ユーザー管理</h1>
        <a href="{{ route('users.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規ユーザー
        </a>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @foreach ($this->users as $user)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $user->name }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $user->email }}</span>
                    @if ($user->is_admin)
                        <span class="ml-2 rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-700">管理者</span>
                    @endif
                    @if ($user->status === \App\Enums\UserStatus::Locked)
                        <span class="ml-2 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">ロック中</span>
                    @endif
                    @if ($user->authSource)
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">LDAP: {{ $user->authSource->name }}</span>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('users.edit', $user) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
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
