{{--
    A small coloured label. The tone carries meaning (see App\Support\Ui\IssueBadges); the text
    always says it too, so colour is never the only signal.
--}}
@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-neutral-100 text-neutral-700',
        'brand' => 'bg-brand-subtlest text-brand-bolder',
        'warning' => 'bg-warning-subtlest text-warning-bolder',
        'danger' => 'bg-danger-subtlest text-danger-bolder',
        'success' => 'bg-success-subtlest text-success-bolder',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap '.($tones[$tone] ?? $tones['neutral'])]) }}>{{ $slot }}</span>
