<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Setting;
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

    #[Computed]
    public function usesStatusForDoneRatio(): bool
    {
        return Setting::get('issue_done_ratio', 'issue_field') === 'issue_status';
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

    /**
     * Matches Redmine's IssueStatus.update_issue_done_ratios: a manual
     * admin action (not triggered automatically on every status edit)
     * that sets done_ratio to each status's default_done_ratio for
     * every issue currently in that status. Only meaningful — and only
     * offered — while issue_done_ratio is 'issue_status'.
     */
    public function updateIssueDoneRatios(): void
    {
        $this->authorize('viewAny', IssueStatus::class);

        if (! $this->usesStatusForDoneRatio) {
            return;
        }

        IssueStatus::query()
            ->whereNotNull('default_done_ratio')
            ->get(['id', 'default_done_ratio'])
            ->each(fn (IssueStatus $status) => Issue::query()
                ->where('status_id', $status->id)
                ->update(['done_ratio' => $status->default_done_ratio]));

        session()->flash('status', '既存の課題の進捗率を更新しました。');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">ステータス管理</h1>
        <div class="flex gap-2">
            @if ($this->usesStatusForDoneRatio)
                <button wire:click="updateIssueDoneRatios"
                    wire:confirm="既存の全課題の進捗率を、現在のステータスの既定値で上書きします。よろしいですか?"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    既存課題の進捗率を一括更新
                </button>
            @endif
            <a href="{{ route('issue-statuses.create') }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規ステータス
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

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
