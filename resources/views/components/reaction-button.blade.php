@props(['reactable', 'type'])

@php
    $count = $reactable->reactions->count();
    $reacted = $reactable->isReactedBy(auth()->user());
    $canReact = app(\App\Services\ReactionService::class)->canReact(auth()->user(), $reactable);

    // The 👍 glyph is aria-hidden and the counter is a bare number, so with
    // no explicit label a screen reader announces this control as just
    // "button" (nobody has reacted yet) or "3" — neither says what it does.
    $label = ($canReact && $reacted ? 'いいねを取り消す' : 'いいね').($count > 0 ? "（{$count}件）" : '');
@endphp

@if ($canReact || $count > 0)
    <button
        type="button"
        data-reaction="{{ $type }}:{{ $reactable->id }}:{{ $count }}"
        aria-label="{{ $label }}"
        @if ($canReact) aria-pressed="{{ $reacted ? 'true' : 'false' }}" wire:click="toggleReaction('{{ $type }}', {{ $reactable->id }})" @else disabled @endif
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs '
            .($reacted ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500')
            .($canReact ? ' hover:bg-gray-100' : '')]) }}
    >
        <span aria-hidden="true">👍</span>
        @if ($count > 0)
            <span>{{ $count }}</span>
        @endif
    </button>
@endif
