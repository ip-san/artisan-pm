@props(['media'])

<span {{ $attributes->merge(['class' => 'text-neutral-400']) }}>{{ __(':count回', ['count' => (int) $media->getCustomProperty('download_count', 0)]) }}</span>
