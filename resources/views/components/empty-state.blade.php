{{--
    What a list shows when it has nothing in it: say so plainly, and when the viewer can fix that,
    offer the next step (the slot) instead of leaving a bare "no items" line.
--}}
@props(['icon' => 'list', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-4 py-10 text-center']) }} data-empty-state>
    <span class="mb-3 flex size-11 items-center justify-center rounded-full bg-brand-subtlest text-brand-bold">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <p class="text-sm font-semibold text-neutral-900">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-neutral-600">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
