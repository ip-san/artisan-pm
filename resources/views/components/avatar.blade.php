{{--
    A user's avatar: their Gravatar when gravatar_enabled, else a coloured
    circle with their initials (Redmine's avatar helper). With the "initials"
    Gravatar fallback both show, the Gravatar covering the circle when the
    address is known.
--}}
@props(['user', 'size' => 24])

@php
    $name = $user?->name ?? '';
    $label = $user?->displayName() ?? '';
    $px = (int) $size;
    $showGravatar = $user !== null && \App\Support\Avatar\UserAvatar::gravatarEnabled();
    $fallback = ! $showGravatar || \App\Support\Avatar\UserAvatar::defaultStyle() === 'initials';
@endphp

@if ($user !== null)
    <span {{ $attributes->merge(['class' => 'relative inline-block shrink-0 align-middle']) }} style="width: {{ $px }}px; height: {{ $px }}px" data-avatar title="{{ $label }}">
        @if ($fallback)
            <span class="absolute inset-0 flex items-center justify-center rounded-full font-semibold text-white"
                style="background: {{ \App\Support\Avatar\UserAvatar::color($user) }}; font-size: {{ max(9, (int) ($px * 0.42)) }}px">{{ \App\Support\Avatar\UserAvatar::initials($user) }}</span>
        @endif
        @if ($showGravatar)
            <img src="{{ \App\Support\Avatar\UserAvatar::gravatarUrl($user->email, $px) }}" alt="{{ $label }}" width="{{ $px }}" height="{{ $px }}"
                loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 rounded-full" onerror="this.remove()">
        @endif
    </span>
@endif
