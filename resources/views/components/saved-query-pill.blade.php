{{--
    A15-07b: shared "load / edit / delete" pill for a saved query bar,
    mirroring the issue list's own inline markup (A15-07). Reused by the
    time entry list, Gantt, project list and user list saved-query bars,
    which previously only supported create+load.

    Requires the consuming component to expose loadQuery()/editQuery()/
    deleteQuery() actions with the same signatures the issue list uses
    (Query::editableBy()/QueryPolicy gate edit/delete server-side too, so
    this markup hiding the buttons is presentation only).
--}}
@props(['query'])
<span wire:key="saved-query-{{ $query->id }}" class="inline-flex items-center gap-1 rounded-full border border-neutral-300 py-1 pl-3 pr-1 text-neutral-700">
    <button type="button" wire:click="loadQuery({{ $query->id }})" class="hover:underline">{{ $query->name }}</button>
    @if ($query->editableBy(auth()->user()))
        <button type="button" wire:click="editQuery({{ $query->id }})" class="rounded px-1 text-xs text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" title="{{ __('編集') }}">{{ __('編集') }}</button>
        <button type="button" wire:click="deleteQuery({{ $query->id }})" wire:confirm="{{ __('このクエリを削除しますか?') }}" class="rounded px-1 text-xs text-neutral-400 hover:bg-danger-subtlest hover:text-danger-bolder" title="{{ __('削除') }}">{{ __('削除') }}</button>
    @endif
</span>
