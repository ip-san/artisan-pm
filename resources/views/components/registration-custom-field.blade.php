{{--
    A custom field on the plain (non-Livewire) registration form: posted as
    custom_fields[<id>] (an array for a multi-value field), re-filled from
    old() after a failed submit.
--}}
@props(['field'])
@php
    $name = "custom_fields[{$field->id}]".($field->multiple ? '[]' : '');
    $old = old("custom_fields.{$field->id}");
    $format = $field->field_format;
    $isChoice = in_array($format, [\App\Enums\CustomFieldFormat::List, \App\Enums\CustomFieldFormat::Enumeration], true);
    $inputClass = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm';
@endphp

<div>
    <label class="block text-sm font-medium text-gray-700">
        {{ $field->name }}
        @if ($field->is_required)<span class="text-red-500">*</span>@endif
    </label>

    @if ($format === \App\Enums\CustomFieldFormat::Bool)
        <input type="hidden" name="{{ $name }}" value="0">
        <input type="checkbox" name="{{ $name }}" value="1" @checked($old === '1' || $old === 1) class="mt-1 rounded border-gray-300">
    @elseif ($isChoice)
        <select name="{{ $name }}" @if ($field->multiple) multiple @endif class="{{ $inputClass }}">
            @unless ($field->multiple)
                <option value="">選択してください</option>
            @endunless
            @foreach ($field->format()->options($field) as $value => $label)
                <option value="{{ $value }}" @selected(in_array((string) $value, array_map('strval', (array) $old), true))>{{ $label }}</option>
            @endforeach
        </select>
    @elseif ($format === \App\Enums\CustomFieldFormat::Text)
        <textarea name="{{ $name }}" rows="3" class="{{ $inputClass }}">{{ is_array($old) ? '' : $old }}</textarea>
    @elseif ($format === \App\Enums\CustomFieldFormat::Date)
        <input type="date" name="{{ $name }}" value="{{ is_array($old) ? '' : $old }}" class="{{ $inputClass }}">
    @elseif (in_array($format, [\App\Enums\CustomFieldFormat::Int, \App\Enums\CustomFieldFormat::Float], true))
        <input type="number" step="any" name="{{ $name }}" value="{{ is_array($old) ? '' : $old }}" class="{{ $inputClass }}">
    @else
        <input type="text" name="{{ $name }}" value="{{ is_array($old) ? '' : $old }}" class="{{ $inputClass }}">
    @endif

    @if ($field->description)
        <p class="mt-1 text-xs text-gray-500">{{ $field->description }}</p>
    @endif
</div>
