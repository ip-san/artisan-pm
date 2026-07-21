<?php

use App\Models\AuthSource;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', AuthSource::class);
    }

    #[Computed]
    public function authSources(): Collection
    {
        return AuthSource::query()->withCount('users')->orderBy('name')->get();
    }

    public function delete(int $authSourceId): void
    {
        $authSource = AuthSource::findOrFail($authSourceId);
        $this->authorize('delete', $authSource);
        $authSource->delete();

        unset($this->authSources);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">LDAP認証ソース</h1>
        <a href="{{ route('auth-sources.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規認証ソース
        </a>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->authSources as $source)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $source->name }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $source->host }}:{{ $source->port }}</span>
                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                        {{ $source->usesSearchThenBind() ? 'search+bind' : 'direct bind' }}
                    </span>
                    @if ($source->onthefly_register)
                        <span class="ml-2 rounded bg-green-50 px-1.5 py-0.5 text-xs text-green-700">自動登録</span>
                    @endif
                    <span class="ml-2 text-xs text-gray-500">{{ $source->users_count }} 人</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('auth-sources.edit', $source) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                    <button wire:click="delete({{ $source->id }})" wire:confirm="この認証ソースを削除しますか?紐づくユーザーはログインできなくなります。"
                        class="text-sm text-red-600 hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">認証ソースがありません。</li>
        @endforelse
    </ul>
</div>
