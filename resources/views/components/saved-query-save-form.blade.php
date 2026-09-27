{{--
    Shared "save this query" form body for the issues and time-entries
    lists. Binds to the conventional property names both Volt components
    declare: newQueryName / newQueryVisibility / newQueryRoleIds, plus
    their canManagePublicQueries and availableRoles computeds. The
    visibility downgrade for users without manage_public_queries is
    enforced server-side in Query::resolveVisibility() — hiding the
    selector here is presentation only.

    showIsForAll/editing are optional (default off) so a caller that
    hasn't wired newQueryIsForAll/editingQueryId/cancelEditQuery (not
    every saved-query screen has editing wired up yet) keeps working
    unchanged.
--}}
@props(['canManagePublicQueries', 'visibility', 'roles', 'showIsForAll' => false, 'editing' => false])
<form wire:submit="saveQuery" class="mt-3 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-3">
    <input type="text" wire:model="newQueryName" placeholder="{{ __('クエリ名') }}" class="rounded-md border-neutral-300 text-sm">

    @if ($canManagePublicQueries)
        <select wire:model.live="newQueryVisibility" class="rounded-md border-neutral-300 text-sm">
            <option value="private">{{ __('非公開') }}</option>
            <option value="roles">{{ __('特定ロールに公開') }}</option>
            <option value="public">{{ __('全員に公開') }}</option>
        </select>

        @if ($visibility === 'roles')
            <span class="flex flex-wrap items-center gap-2 text-xs text-neutral-600">
                @foreach ($roles as $role)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="newQueryRoleIds" value="{{ $role->id }}" class="rounded border-neutral-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </span>
            @error('newQueryRoleIds') <span class="text-sm text-danger-bolder">{{ $message }}</span> @enderror
        @endif
    @else
        <span class="text-xs text-neutral-500">{{ __('(非公開クエリとして保存されます)') }}</span>
    @endif

    @if ($showIsForAll)
        <label class="flex items-center gap-1 text-xs text-neutral-600">
            <input type="checkbox" wire:model="newQueryIsForAll" data-testid="query-is-for-all" class="rounded border-neutral-300">
            {{ __('全プロジェクト向け') }}
        </label>
    @endif

    <button type="submit" class="rounded-md bg-brand-bold px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-hovered">{{ $editing ? __('更新') : __('保存') }}</button>
    @if ($editing)
        <button type="button" wire:click="cancelEditQuery" class="text-sm text-neutral-500 hover:underline">{{ __('キャンセル') }}</button>
    @endif
    @error('newQueryName') <span class="text-sm text-danger-bolder">{{ $message }}</span> @enderror
</form>
