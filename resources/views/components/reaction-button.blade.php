@props(['reactable', 'type'])

@php
    $count = $reactable->reactions->count();
    $reacted = $reactable->isReactedBy(auth()->user());
    $canReact = app(\App\Services\ReactionService::class)->canReact(auth()->user(), $reactable);
@endphp

@if ($canReact || $count > 0)
    <button
        type="button"
        data-reaction="{{ $type }}:{{ $reactable->id }}:{{ $count }}"
        @if ($canReact) wire:click="toggleReaction('{{ $type }}', {{ $reactable->id }})" @else disabled @endif
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
