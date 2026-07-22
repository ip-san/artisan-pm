@props(['field', 'wireModel', 'required' => false, 'disabled' => false])
@php $path = "{$wireModel}.{$field->id}"; @endphp

<div>
    <label class="block text-sm font-medium text-gray-700">
        {{ $field->name }}
        @if ($required)<span class="text-red-500">*</span>@endif
    </label>

    @if ($field->field_format === \App\Enums\CustomFieldFormat::Bool)
        <input type="checkbox" wire:model="{{ $path }}" @disabled($disabled) class="mt-1 rounded border-gray-300">
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::List)
        <select wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value="">選択してください</option>
            @foreach ($field->possible_values ?? [] as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Enumeration)
        <select wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value="">選択してください</option>
            @foreach ($field->enumerationOptions()->where('active', true)->get() as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Text)
        <textarea wire:model="{{ $path }}" rows="3" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Date)
        <input type="date" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Int || $field->field_format === \App\Enums\CustomFieldFormat::Float)
        <input type="number" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @else
        <input type="text" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @endif

    @error($path) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
