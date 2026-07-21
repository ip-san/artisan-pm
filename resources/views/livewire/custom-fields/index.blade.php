<?php

use App\Models\CustomField;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', CustomField::class);
    }

    #[Computed]
    public function customFields(): Collection
    {
        return CustomField::query()->with('trackers')->orderBy('position')->get();
    }

    public function delete(int $customFieldId): void
    {
        $field = CustomField::findOrFail($customFieldId);
        $this->authorize('delete', $field);
        $field->delete();

        unset($this->customFields);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">カスタムフィールド管理</h1>
        <a href="{{ route('custom-fields.create') }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規カスタムフィールド
        </a>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->customFields as $field)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $field->name }}</span>
                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $field->field_format->value }}</span>
                    @if ($field->is_required)
                        <span class="ml-2 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">必須</span>
                    @endif
                    <span class="ml-2 text-xs text-gray-500">
                        {{ $field->trackers->pluck('name')->join(', ') ?: 'トラッカー未設定' }}
                    </span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('custom-fields.edit', $field) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                    <button wire:click="delete({{ $field->id }})" wire:confirm="このカスタムフィールドを削除しますか?"
                        class="text-sm text-red-600 hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">カスタムフィールドがありません。</li>
        @endforelse
    </ul>
</div>
