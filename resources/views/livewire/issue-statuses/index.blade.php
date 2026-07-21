<?php

use App\Models\IssueStatus;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', IssueStatus::class);
    }

    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->withCount('issues')->orderBy('position')->get();
    }

    public function delete(int $statusId): void
    {
        $status = IssueStatus::findOrFail($statusId);
        $this->authorize('delete', $status);

        if ($status->issues()->exists()) {
            session()->flash('error', 'このステータスを使用している課題があるため削除できません。');

            return;
        }

        $status->delete();

        unset($this->statuses);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">ステータス管理</h1>
        <a href="{{ route('issue-statuses.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規ステータス
        </a>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->statuses as $status)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $status->name }}</span>
                    @if ($status->is_closed)
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">完了扱い</span>
                    @endif
                    <span class="ml-2 text-xs text-gray-500">{{ $status->issues_count }} 課題</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('issue-statuses.edit', $status) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                    <button wire:click="delete({{ $status->id }})" wire:confirm="このステータスを削除しますか?"
                        class="text-sm text-red-600 hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">ステータスがありません。</li>
        @endforelse
    </ul>
</div>
