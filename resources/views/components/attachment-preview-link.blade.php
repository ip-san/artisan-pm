@props(['media'])

@if (\App\Support\Attachments\AttachmentPreview::isPreviewable($media))
    <a href="{{ route('attachments.preview', $media) }}" class="text-xs text-indigo-600 hover:underline" data-attachment-preview-link>表示</a>
@endif
