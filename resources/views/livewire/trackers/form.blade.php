<?php

use App\Models\IssueStatus;
use App\Models\Tracker;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Tracker $tracker = null;

    public string $name = '';

    public string $description = '';

    public ?int $default_status_id = null;

    public function mount(?Tracker $tracker = null): void
    {
        if ($tracker?->exists) {
            $this->authorize('update', $tracker);

            $this->tracker = $tracker;
            $this->name = $tracker->name;
            $this->description = (string) $tracker->description;
            $this->default_status_id = $tracker->default_status_id;
        } else {
            $this->authorize('create', Tracker::class);
        }
    }

    /**
     * @return Collection<int, IssueStatus>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('trackers', 'name')->ignore($this->tracker?->id)],
            'description' => ['nullable', 'string'],
            'default_status_id' => ['nullable', 'exists:issue_statuses,id'],
        ]);

        if ($this->tracker) {
            $this->tracker->update($data);
        } else {
            Tracker::create($data);
        }

        $this->redirect(route('trackers.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $tracker ? 'トラッカーを編集' : '新規トラッカー' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">既定のステータス(新規課題作成時)</label>
            <select wire:model="default_status_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし(全体の先頭ステータスを使用)</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
            @error('default_status_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('trackers.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
