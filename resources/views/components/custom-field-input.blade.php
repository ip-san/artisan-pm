@props(['field', 'wireModel', 'required' => false, 'disabled' => false, 'project' => null, 'record' => null, 'current' => null])
@php
    $path = "{$wireModel}.{$field->id}";
    $inputId = 'field-'.str_replace('.', '-', $path);
@endphp

<div>
    <label for="{{ $inputId }}" class="block text-sm font-medium text-neutral-700">
        {{ $field->name }}
        @if ($required)<span class="text-danger-subtle">*</span>@endif
    </label>
    @if ($field->description)
        <p class="text-xs text-neutral-500">{{ $field->description }}</p>
    @endif

    @if ($field->field_format === \App\Enums\CustomFieldFormat::Bool)
        <input type="checkbox" id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled) class="mt-1 rounded border-neutral-300">
    @elseif (in_array($field->field_format, [\App\Enums\CustomFieldFormat::List, \App\Enums\CustomFieldFormat::Enumeration, \App\Enums\CustomFieldFormat::User, \App\Enums\CustomFieldFormat::Version], true))
        @if ($field->multiple)
            <select multiple id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled) data-multiple-choice
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @foreach ($field->optionsFor($project) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        @else
            <select id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled)
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="">{{ __('選択してください') }}</option>
                @foreach ($field->optionsFor($project) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        @endif
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Attachment)
        @php
            $attachmentInput = $current;
            $currentFile = is_scalar($attachmentInput) && ctype_digit((string) $attachmentInput) && $record !== null
                ? $record->customDisplayValue($field)
                : null;
            $currentFile = $currentFile instanceof \App\Support\Attachments\AttachmentFieldFile && $currentFile->mediaId === (int) $attachmentInput ? $currentFile : null;
            $allowedExtensions = \App\CustomFields\Formats\AttachmentFormat::allowedExtensions($field);
        @endphp
        <div class="mt-1 space-y-1" data-custom-field-attachment-input>
            @if ($currentFile !== null)
                <div class="flex items-center gap-3 text-sm">
                    <a href="{{ $currentFile->url() }}" class="text-brand-bold hover:underline">{{ $currentFile->fileName }}</a>
                    @unless ($disabled)
                        <button type="button" wire:click="$set('{{ $path }}', '')" class="text-danger-bold hover:underline">{{ __('削除') }}</button>
                    @endunless
                </div>
            @elseif ($attachmentInput instanceof \Illuminate\Http\UploadedFile)
                <div class="text-sm text-neutral-700">{{ $attachmentInput->getClientOriginalName() }}</div>
            @endif
            @unless ($disabled)
                <input type="file" id="{{ $inputId }}" wire:model="{{ $path }}" @if ($allowedExtensions !== []) accept="{{ collect($allowedExtensions)->map(fn ($extension) => '.'.$extension)->implode(',') }}" @endif
                    class="block w-full text-sm text-neutral-700">
                @if ($allowedExtensions !== [])
                    <p class="text-xs text-neutral-500">{{ __('許可する拡張子: :extensions', ['extensions' => implode(', ', $allowedExtensions)]) }}</p>
                @endif
            @endunless
        </div>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Text)
        <textarea id="{{ $inputId }}" wire:model="{{ $path }}" rows="3" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Date)
        <input type="date" id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
    @elseif (in_array($field->field_format, [\App\Enums\CustomFieldFormat::Int, \App\Enums\CustomFieldFormat::Float], true))
        <input type="number" id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
    @elseif ($field->field_format === \App\Enums\CustomFieldFormat::Progressbar)
        <select id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-32 rounded-md border-neutral-300 shadow-sm sm:text-sm">
            <option value=""></option>
            @foreach (\App\Support\Issues\DoneRatioSteps::options($field->ratio_interval) as $ratio)
                <option value="{{ $ratio }}">{{ $ratio }} %</option>
            @endforeach
        </select>
    @else
        <input type="text" id="{{ $inputId }}" wire:model="{{ $path }}" @disabled($disabled)
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
    @endif

    @error($path) <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
</div>
