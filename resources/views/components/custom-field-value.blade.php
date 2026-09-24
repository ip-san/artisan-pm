{{--
    Renders one custom field's display value — shared by every "show"
    page that lists custom field values (issues, documents, ...). A Link
    format field renders as a clickable anchor (auto-prefixing "http://"
    via LinkFormat::href() when the stored value has no scheme); every
    other format, and multi-value Link fields (already joined into a
    single comma-separated string upstream), render as plain text. An
    attachment field's file links to its download.
--}}
@props(['field', 'value'])

@if ($value === null || $value === '')
    -
@elseif ($value instanceof \App\Support\Attachments\AttachmentFieldFile)
    <a href="{{ $value->url() }}" class="text-brand-bold hover:underline" data-custom-field-attachment>{{ $value->fileName }}</a>
@elseif ($field->field_format === \App\Enums\CustomFieldFormat::Link && ! $field->multiple)
    <a href="{{ \App\CustomFields\Formats\LinkFormat::href($value) }}" target="_blank" rel="noopener noreferrer" class="text-brand-bold hover:underline">{{ $value }}</a>
@else
    {{ $value }}
@endif
