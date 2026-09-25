@props(['media'])

@if (\App\Models\Setting::get('thumbnails_enabled', true) && $media->hasGeneratedConversion('thumb'))
    <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer">
        <img src="{{ route('attachments.thumb', $media) }}" alt="{{ $media->file_name }}" loading="lazy"
            {{ $attributes->merge(['class' => 'h-10 w-10 rounded border border-neutral-200 object-cover']) }}>
    </a>
@endif
