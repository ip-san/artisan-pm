@props(['reactable', 'type'])

@php
    $count = $reactable->reactions->count();
    $reacted = $reactable->isReactedBy(auth()->user());
    $canReact = app(\App\Services\ReactionService::class)->canReact(auth()->user(), $reactable);

    // The 👍 glyph is aria-hidden and the counter is a bare number, so with
    // no explicit label a screen reader announces this control as just
    // "button" (nobody has reacted yet) or "3" — neither says what it does.
    $label = match (true) {
        $canReact && $reacted && $count > 0 => __('いいねを取り消す（:count件）', ['count' => $count]),
        $canReact && $reacted => __('いいねを取り消す'),
        $count > 0 => __('いいね（:count件）', ['count' => $count]),
        default => __('いいね'),
    };
@endphp

@if ($canReact || $count > 0)
    <button
        type="button"
        data-reaction="{{ $type }}:{{ $reactable->id }}:{{ $count }}"
        aria-label="{{ $label }}"
        @if ($canReact) aria-pressed="{{ $reacted ? 'true' : 'false' }}" wire:click="toggleReaction('{{ $type }}', {{ $reactable->id }})" @else disabled @endif
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs '
            .($reacted ? 'bg-brand-subtler text-brand-bolder' : 'text-neutral-500')
            .($canReact ? ' hover:bg-neutral-100' : '')]) }}
    >
        <span aria-hidden="true">👍</span>
        @if ($count > 0)
            <span>{{ $count }}</span>
        @endif
    </button>
@endif
