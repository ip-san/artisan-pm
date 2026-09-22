<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public EnumerationType $type;

    public function mount(EnumerationType $type): void
    {
        $this->authorize('viewAny', Enumeration::class);

        $this->type = $type;
    }

    /**
     * Global values only — project-specific TimeEntryActivity overrides
     * (see Project::activities()) live in the same table but must never
     * appear in this system-wide management list.
     */
    #[Computed]
    public function enumerations(): Collection
    {
        return Enumeration::query()->ofType($this->type)->whereNull('project_id')->orderBy('position')->get();
    }

    public function makeDefault(int $enumerationId): void
    {
        $enumeration = Enumeration::findOrFail($enumerationId);
        $this->authorize('update', $enumeration);

        $enumeration->makeDefault();

        unset($this->enumerations);
    }

    public function delete(int $enumerationId): void
    {
        $enumeration = Enumeration::findOrFail($enumerationId);
        $this->authorize('delete', $enumeration);

        if ($this->type === EnumerationType::IssuePriority && $enumeration->issues()->exists()) {
            session()->flash('error', 'この優先度を使用している課題があるため削除できません。');

            return;
        }

        if ($this->type === EnumerationType::TimeEntryActivity && $enumeration->timeEntries()->exists()) {
            session()->flash('error', 'この工数区分を使用している作業時間記録があるため削除できません。');

            return;
        }

        $enumeration->delete();

        unset($this->enumerations);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">値の一覧管理</h1>
        <a href="{{ route('enumerations.create', $type->value) }}"
            class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            新規作成
        </a>
    </div>

    <div class="mb-4 flex gap-4 border-b border-neutral-200 text-sm">
        @foreach (EnumerationType::cases() as $tab)
            <a href="{{ route('enumerations.index', $tab->value) }}"
                class="border-b-2 px-1 pb-2 {{ $tab === $type ? 'border-brand-bold font-medium text-brand-bold' : 'border-transparent text-neutral-500 hover:text-neutral-700' }}">
                @if ($tab === EnumerationType::IssuePriority) 課題の優先度
                @elseif ($tab === EnumerationType::TimeEntryActivity) 作業時間の活動
                @else 文書カテゴリ
                @endif
            </a>
        @endforeach
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->enumerations as $enumeration)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $enumeration->name }}</span>
                    @if ($enumeration->is_default)
                        <span class="ml-2 rounded bg-brand-subtlest px-1.5 py-0.5 text-xs text-brand-bolder">既定</span>
                    @endif
                    @unless ($enumeration->active)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">無効</span>
                    @endunless
                </div>
                <div class="flex gap-3">
                    @unless ($enumeration->is_default)
                        <button wire:click="makeDefault({{ $enumeration->id }})" class="text-sm text-neutral-600 hover:underline">既定にする</button>
                    @endunless
                    <a href="{{ route('enumerations.edit', [$type->value, $enumeration]) }}" class="text-sm text-brand-bold hover:underline">編集</a>
                    <button wire:click="delete({{ $enumeration->id }})" wire:confirm="削除しますか?"
                        class="text-sm text-danger-bolder hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">登録がありません。</li>
        @endforelse
    </ul>
</div>
