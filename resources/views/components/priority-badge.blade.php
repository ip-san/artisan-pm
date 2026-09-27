{{-- Only priorities above the default get a colour; normal and low ones stay plain text so the list stays calm. --}}
@props(['priority'])

@php $tone = \App\Support\Ui\IssueBadges::priorityTone($priority); @endphp

@if ($tone === \App\Support\Ui\IssueBadges::Neutral)
    <span {{ $attributes->merge(['class' => 'text-neutral-700']) }} data-priority-badge>{{ $priority->name }}</span>
@else
    <x-badge :tone="$tone" data-priority-badge {{ $attributes }}>
        <svg class="size-3" viewBox="0 0 12 12" fill="currentColor" aria-hidden="true"><path d="M6 2l4 5H2z"/></svg>{{ $priority->name }}
    </x-badge>
@endif
