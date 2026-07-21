<?php

use App\Models\Tracker;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Tracker::class);
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->withCount(['projects', 'issues'])->orderBy('position')->get();
    }

    public function delete(int $trackerId): void
    {
        $tracker = Tracker::findOrFail($trackerId);
        $this->authorize('delete', $tracker);

        if ($tracker->issues()->exists()) {
            session()->flash('error', 'このトラッカーを使用している課題があるため削除できません。');

            return;
        }

        $tracker->delete();

        unset($this->trackers);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">トラッカー管理</h1>
        <a href="{{ route('trackers.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規トラッカー
        </a>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->trackers as $tracker)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $tracker->name }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $tracker->projects_count }} プロジェクト・{{ $tracker->issues_count }} 課題</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('trackers.edit', $tracker) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                    <button wire:click="delete({{ $tracker->id }})" wire:confirm="このトラッカーを削除しますか?"
                        class="text-sm text-red-600 hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">トラッカーがありません。</li>
        @endforelse
    </ul>
</div>
