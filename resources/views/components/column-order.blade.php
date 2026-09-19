{{--
    Reorder control for a list's selected columns: one row per selected
    column with move-earlier / move-later buttons, calling the surrounding
    Livewire component's moveColumn() (App\Concerns\ReordersColumns).
    Nothing is rendered until two or more columns are selected.
--}}
@props(['columns', 'labels'])

@if (count($columns) > 1)
    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700" data-column-order>
        列の並び順:
        @foreach ($columns as $index => $key)
            <span class="flex items-center gap-1 rounded border border-gray-200 bg-white px-1.5 py-0.5" wire:key="column-order-{{ $key }}">
                {{ $labels[$key] ?? $key }}
                <button type="button" wire:click="moveColumn('{{ $key }}', -1)" @disabled($index === 0)
                    class="text-gray-500 hover:text-gray-900 disabled:opacity-30" title="前へ">◀</button>
                <button type="button" wire:click="moveColumn('{{ $key }}', 1)" @disabled($loop->last)
                    class="text-gray-500 hover:text-gray-900 disabled:opacity-30" title="後ろへ">▶</button>
            </span>
        @endforeach
    </div>
@endif
