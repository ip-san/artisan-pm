{{--
    Reorder control for a list's selected columns: one row per selected
    column with move-earlier / move-later buttons, calling the surrounding
    Livewire component's moveColumn() (App\Concerns\ReordersColumns), or,
    with `setting`, its moveSettingColumn('<setting>', …) (the settings page).
    Nothing is rendered until two or more columns are selected.
--}}
@props(['columns', 'labels', 'setting' => null])
@php($moveCall = fn (string $key, int $delta) => $setting === null ? "moveColumn('{$key}', {$delta})" : "moveSettingColumn('{$setting}', '{$key}', {$delta})")

@if (count($columns) > 1)
    <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-700" data-column-order>
        {{ __('列の並び順:') }}
        @foreach ($columns as $key)
            <span class="flex items-center gap-1 rounded border border-neutral-200 bg-surface px-1.5 py-0.5" wire:key="column-order-{{ $setting ? $setting.'-' : '' }}{{ $key }}">
                {{ $labels[$key] ?? $key }}
                <button type="button" wire:click="{{ $moveCall($key, -1) }}" @disabled($loop->first)
                    class="text-neutral-500 hover:text-neutral-900 disabled:opacity-30" title="{{ __('前へ') }}">◀</button>
                <button type="button" wire:click="{{ $moveCall($key, 1) }}" @disabled($loop->last)
                    class="text-neutral-500 hover:text-neutral-900 disabled:opacity-30" title="{{ __('後ろへ') }}">▶</button>
            </span>
        @endforeach
    </div>
@endif
