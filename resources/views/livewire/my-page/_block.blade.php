{{--
    One dashboard block's <li>, shared by the three area columns in
    my-page/index.blade.php via @include (so it keeps access to the
    enclosing Livewire component's $this and $settingsBlockId/$settingsForm
    without prop-drilling every value through a Blade component).
--}}
<li wire:key="block-{{ $block->id }}" wire:sort:item="{{ $block->id }}"
    class="cursor-move rounded-md border border-neutral-200 bg-surface">
    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-2">
        <span class="text-sm font-semibold text-neutral-900">{{ $this->blockLabel($block->block_key) }}</span>
        <div wire:sort:ignore class="flex items-center gap-2">
            @foreach (\App\Enums\DashboardArea::cases() as $targetArea)
                @if ($targetArea !== $block->area)
                    <button wire:click="moveToArea({{ $block->id }}, '{{ $targetArea->value }}')"
                        class="text-xs text-neutral-500 hover:text-neutral-700" title="{{ __('この列へ移動:') }} {{ $this->areaLabel($targetArea) }}">
                        {{ $this->areaShortLabel($targetArea) }}
                    </button>
                @endif
            @endforeach
            @if ($this->settingFieldsFor($block->block_key) !== [])
                <button wire:click="openSettings({{ $block->id }})" data-block-settings class="text-xs text-neutral-600 hover:underline">
                    {{ __('設定') }}
                </button>
            @endif
            <button wire:click="removeBlock({{ $block->id }})" class="text-xs text-danger-bolder hover:underline">
                {{ __('削除') }}
            </button>
        </div>
    </div>
    @if ($settingsBlockId === $block->id)
        <form wire:submit="saveSettings" wire:sort:ignore data-block-settings-form class="space-y-3 border-b border-neutral-100 bg-neutral-50 px-4 py-3">
            @foreach ($this->settingFieldsFor($block->block_key) as $name => $field)
                <div wire:key="setting-{{ $block->id }}-{{ $name }}">
                    <span class="block text-xs font-medium text-neutral-700">{{ $field['label'] }}</span>
                    @if ($field['type'] === 'number')
                        <input type="number" min="1" wire:model="settingsForm.{{ $name }}" placeholder="{{ $field['placeholder'] ?? '' }}"
                            class="mt-1 w-32 rounded-md border-neutral-300 text-sm">
                    @elseif ($field['type'] === 'select')
                        <select wire:model="settingsForm.{{ $name }}" class="mt-1 rounded-md border-neutral-300 text-sm">
                            <option value=""></option>
                            @foreach ($field['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <div class="mt-1 flex flex-wrap gap-3">
                            @foreach ($field['options'] as $value => $label)
                                <label class="flex items-center gap-1 text-xs text-neutral-700">
                                    <input type="checkbox" wire:model="settingsForm.{{ $name }}" value="{{ $value }}" class="rounded border-neutral-300">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-brand-bold px-3 py-1 text-xs font-medium text-white hover:bg-brand-hovered">{{ __('保存') }}</button>
                <button type="button" wire:click="closeSettings" class="text-xs text-neutral-600 hover:underline">{{ __('キャンセル') }}</button>
            </div>
        </form>
    @endif
    @if ($block->block_key === 'calendar')
        <div wire:sort:ignore class="p-3">
            <x-my-page-week-calendar :days="$this->calendarWeek()" />
        </div>
    @else
        <ul class="divide-y divide-neutral-100">
            @forelse ($this->blockRows($block->block_key, $block->settings ?? []) as $row)
                <li class="px-4 py-2 text-sm">
                    <a href="{{ $row->url }}" class="text-brand-bold hover:underline">{{ $row->title }}</a>
                    @if ($row->statusBadge || $row->priorityBadge || $row->meta)
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-600">
                            @if ($row->statusBadge)
                                <x-status-badge :status="$row->issue->status" />
                            @endif
                            @if ($row->priorityBadge && \App\Support\Ui\IssueBadges::priorityTone($row->issue->priority) !== \App\Support\Ui\IssueBadges::Neutral)
                                <x-priority-badge :priority="$row->issue->priority" />
                            @endif
                            @if ($row->meta)
                                <span>{{ $row->meta }}</span>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-3 text-center text-sm text-neutral-500">{{ __('項目がありません。') }}</li>
            @endforelse
        </ul>
    @endif
</li>
