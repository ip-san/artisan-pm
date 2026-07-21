<?php

use App\Models\IssueStatus;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?IssueStatus $issueStatus = null;

    public string $name = '';

    public bool $is_closed = false;

    public function mount(?IssueStatus $issueStatus = null): void
    {
        if ($issueStatus?->exists) {
            $this->authorize('update', $issueStatus);

            $this->issueStatus = $issueStatus;
            $this->name = $issueStatus->name;
            $this->is_closed = $issueStatus->is_closed;
        } else {
            $this->authorize('create', IssueStatus::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('issue_statuses', 'name')->ignore($this->issueStatus?->id)],
            'is_closed' => ['boolean'],
        ]);

        if ($this->issueStatus) {
            $this->issueStatus->update($data);
        } else {
            IssueStatus::create($data);
        }

        $this->redirect(route('issue-statuses.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $issueStatus ? 'ステータスを編集' : '新規ステータス' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_closed" class="rounded border-gray-300">
            完了扱いのステータスにする
        </label>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('issue-statuses.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
