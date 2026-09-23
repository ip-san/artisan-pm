{{--
    Shared filter-builder body for every list backed by QueryFilterEngine
    (issues, time entries, gantt, calendar, projects, users). Binds to the conventional property and
    action names all of those Volt components declare: activeFilterKeys /
    filterOperators / filterValues plus addFilter() / removeFilter().
    The apply button stays in each page, since what surrounds it differs.

    step="0.01" on number inputs also covers decimal fields (hours) —
    FilterFieldType has no distinct decimal case, and any whole number is
    still a valid multiple of 0.01, so true integer fields are unaffected.
--}}
@props(['engine', 'activeFilterKeys', 'filterOperators'])
@php $addFilterOptions = \App\Support\Query\FilterSelectOptions::grouped($engine->fields(), $activeFilterKeys); @endphp
<div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
    <label for="add-filter-select" class="font-medium text-neutral-700">{{ __('フィルタ追加') }}</label>
    {{-- Redmine's "add filter" select: choosing a filter adds its row, then the select goes back to blank. --}}
    <select id="add-filter-select" x-on:change="if ($event.target.value !== '') { $wire.addFilter($event.target.value); $event.target.value = '' }" class="rounded-md border-neutral-300 text-sm">
        <option value=""></option>
        @foreach ($addFilterOptions['ungrouped'] as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
        @foreach ($addFilterOptions['groups'] as $groupLabel => $options)
            <optgroup label="{{ $groupLabel }}">
                @foreach ($options as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
</div>

@if ($activeFilterKeys !== [])
    <div class="space-y-2">
        @foreach ($activeFilterKeys as $key)
            @php $field = $engine->field($key); @endphp
            @continue(! $field)
            <div wire:key="filter-row-{{ $key }}" class="flex flex-wrap items-center gap-2">
                <span class="w-28 text-sm text-neutral-700">{{ $field->label() }}</span>
                <select wire:model="filterOperators.{{ $key }}" class="rounded-md border-neutral-300 text-sm">
                    @foreach ($field->operators() as $operator)
                        <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                    @endforeach
                </select>

                @php $selectedOperator = \App\Enums\FilterOperator::tryFrom($filterOperators[$key] ?? ''); @endphp
                @if ($selectedOperator?->requiresValue() ?? true)
                    @if ($selectedOperator?->takesProject())
                        <select wire:model="filterValues.{{ $key }}.0" class="rounded-md border-neutral-300 text-sm">
                            <option value="">{{ __('選択してください') }}</option>
                            @foreach ($field->options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($field->type() === \App\Enums\FilterFieldType::Select && $field->options() !== [])
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::In->value || ($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::NotIn->value)
                            <select wire:model="filterValues.{{ $key }}" multiple class="min-w-[10rem] rounded-md border-neutral-300 text-sm">
                                @foreach ($field->options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <select wire:model="filterValues.{{ $key }}.0" class="rounded-md border-neutral-300 text-sm">
                                <option value="">{{ __('選択してください') }}</option>
                                @foreach ($field->options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                    @elseif ($field->type() === \App\Enums\FilterFieldType::Date)
                        <input type="date" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-neutral-300 text-sm">
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                            <span class="text-neutral-400">{{ __('〜') }}</span>
                            <input type="date" wire:model="filterValues.{{ $key }}.1" class="rounded-md border-neutral-300 text-sm">
                        @endif
                    @elseif ($field->type() === \App\Enums\FilterFieldType::IdList && ! in_array($selectedOperator, [\App\Enums\FilterOperator::GreaterOrEqual, \App\Enums\FilterOperator::LessOrEqual, \App\Enums\FilterOperator::Between], true))
                        {{-- As in Redmine, several issue ids may be given separated by commas. --}}
                        <input type="text" inputmode="numeric" placeholder="1, 2, 3" wire:model="filterValues.{{ $key }}.0" class="w-40 rounded-md border-neutral-300 text-sm">
                    @elseif (in_array($field->type(), [\App\Enums\FilterFieldType::Integer, \App\Enums\FilterFieldType::IdList], true))
                        <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.0" class="w-24 rounded-md border-neutral-300 text-sm">
                        @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                            <span class="text-neutral-400">{{ __('〜') }}</span>
                            <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.1" class="w-24 rounded-md border-neutral-300 text-sm">
                        @endif
                    @else
                        <input type="text" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-neutral-300 text-sm">
                    @endif
                @endif

                <button wire:click="removeFilter('{{ $key }}')" class="text-xs text-danger-bolder hover:underline">{{ __('削除') }}</button>
            </div>
        @endforeach
    </div>
@endif
