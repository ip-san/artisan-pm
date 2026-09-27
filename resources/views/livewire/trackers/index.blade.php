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
            session()->flash('error', __('このトラッカーを使用している課題があるため削除できません。'));

            return;
        }

        $tracker->delete();

        unset($this->trackers);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('トラッカー管理') }}</h1>
        <a href="{{ route('trackers.create') }}"
            class="btn btn-primary">
            {{ __('新規トラッカー') }}
        </a>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
        @forelse ($this->trackers as $tracker)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $tracker->name }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ __(':projects プロジェクト・:issues 課題', ['projects' => $tracker->projects_count, 'issues' => $tracker->issues_count]) }}</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('trackers.edit', $tracker) }}" class="text-sm text-brand-bold hover:underline">{{ __('編集') }}</a>
                    <button wire:click="delete({{ $tracker->id }})" wire:confirm="{{ __('このトラッカーを削除しますか?') }}"
                        class="text-sm text-danger-bolder hover:underline">{{ __('削除') }}</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">{{ __('トラッカーがありません。') }}</li>
        @endforelse
    </ul>
</div>
