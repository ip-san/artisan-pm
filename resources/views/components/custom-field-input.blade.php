@props(['field', 'wireModel', 'required' => false, 'disabled' => false, 'project' => null])
@php $path = "{$wireModel}.{$field->id}"; @endphp

<div>
    <label class="block text-sm font-medium text-gray-700">
        {{ $field->name }}
        @if ($required)<span class="text-red-500">*</span>@endif
    </label>
    @if ($field->description)
        <p class="text-xs text-gray-500">{{ $field->description }}</p>
    @endif

    @if ($field->field_format === \App\Enums\CustomFieldFormat::Bool)
        <input type="checkbox" wire:model="{{ $path }}" @disabled($disabled) class="mt-1 rounded border-gray-300">
    @elseif (in_array($field->field_format, [\App\Enums\CustomFieldFormat::List, \App\Enums\CustomFieldFormat::Enumeration, \App\Enums\CustomFieldFormat::User, \App\Enums\CustomFieldFormat::Version], true))
        <select wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value="">選択してください</option>
            @foreach ($field->optionsFor($project) as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Text)
        <textarea wire:model="{{ $path }}" rows="3" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Date)
        <input type="date" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @elseif (in_array($field->field_format, [\App\Enums\CustomFieldFormat::Int, \App\Enums\CustomFieldFormat::Float], true))
        <input type="number" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Progressbar)
        <select wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-32 rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value=""></option>
            @foreach (\App\Support\Issues\DoneRatioSteps::options($field->ratio_interval) as $ratio)
                <option value="{{ $ratio }}">{{ $ratio }} %</option>
            @endforeach
        </select>
    @else
        <input type="text" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
    @endif

    @error($path) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
