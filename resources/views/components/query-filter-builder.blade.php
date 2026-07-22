{{--
    Shared filter-builder body for every list backed by QueryFilterEngine
    (issues, time entries, gantt). Binds to the conventional property and
    action names all of those Volt components declare: activeFilterKeys /
    filterOperators / filterValues plus addFilter() / removeFilter().
    The apply button stays in each page, since what surrounds it differs.

    step="0.01" on number inputs also covers decimal fields (hours) —
    FilterFieldType has no distinct decimal case, and any whole number is
    still a valid multiple of 0.01, so true integer fields are unaffected.
--}}
@props(['engine', 'activeFilterKeys', 'filterOperators'])
<div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
    <span class="font-medium text-gray-700">フィルタを追加:</span>
    @foreach ($engine->fields() as $field)
        @unless (in_array($field->key(), $activeFilterKeys, true))
            <button wire:key="add-filter-{{ $field->key() }}" wire:click="addFilter('{{ $field->key() }}')" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">
                + {{ $field->label() }}
            </button>
        @endunless
    @endforeach
</div>

@if ($activeFilterKeys !== [])
    <div class="space-y-2">
        @foreach ($activeFilterKeys as $key)
            @php $field = $engine->field($key); @endphp
            @continue(! $field)
            <div wire:key="filter-row-{{ $key }}" class="flex flex-wrap items-center gap-2">
                <span class="w-28 text-sm text-gray-700">{{ $field->label() }}</span>
                <select wire:model="filterOperators.{{ $key }}" class="rounded-md border-gray-300 text-sm">
                    @foreach ($field->operators() as $operator)
                        <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                    @endforeach
                </select>

                @if (($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsEmpty->value && ($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsNotEmpty->value)
                    @if ($field->type() === \App\Enums\FilterFieldType::Select && $field->options() !== [])
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::In->value || ($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::NotIn->value)
                            <select wire:model="filterValues.{{ $key }}" multiple class="min-w-[10rem] rounded-md border-gray-300 text-sm">
                                @foreach ($field->options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <select wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                                <option value="">選択してください</option>
                                @foreach ($field->options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                    @elseif ($field->type() === \App\Enums\FilterFieldType::Date)
                        <input type="date" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                            <span class="text-gray-400">〜</span>
                            <input type="date" wire:model="filterValues.{{ $key }}.1" class="rounded-md border-gray-300 text-sm">
                        @endif
                    @elseif ($field->type() === \App\Enums\FilterFieldType::Integer)
                        <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.0" class="w-24 rounded-md border-gray-300 text-sm">
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                            <span class="text-gray-400">〜</span>
                            <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.1" class="w-24 rounded-md border-gray-300 text-sm">
                        @endif
                    @else
                        <input type="text" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                    @endif
                @endif

                <button wire:click="removeFilter('{{ $key }}')" class="text-xs text-red-600 hover:underline">削除</button>
            </div>
        @endforeach
    </div>
@endif
