<?php

use App\Models\Webhook;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Webhook::class);
    }

    #[Computed]
    public function webhooks(): Collection
    {
        return Webhook::query()->with('project')->orderBy('name')->get();
    }

    public function delete(int $webhookId): void
    {
        $webhook = Webhook::findOrFail($webhookId);
        $this->authorize('delete', $webhook);
        $webhook->delete();

        unset($this->webhooks);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Webhook</h1>
        <a href="{{ route('webhooks.create') }}"
            class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            新規Webhook
        </a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->webhooks as $webhook)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $webhook->name }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $webhook->url }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $webhook->project?->name ?? '全プロジェクト' }}</span>
                    @if (! $webhook->is_active)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">無効</span>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('webhooks.edit', $webhook) }}" class="text-sm text-brand-bold hover:underline">編集</a>
                    <button wire:click="delete({{ $webhook->id }})" wire:confirm="このWebhookを削除しますか?"
                        class="text-sm text-danger-bolder hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">Webhookがありません。</li>
        @endforelse
    </ul>
</div>
