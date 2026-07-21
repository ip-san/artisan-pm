@props(['media'])

<span {{ $attributes->merge(['class' => 'text-gray-400']) }}>{{ (int) $media->getCustomProperty('download_count', 0) }}回</span>
