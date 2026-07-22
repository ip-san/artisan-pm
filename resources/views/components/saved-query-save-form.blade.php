{{--
    Shared "save this query" form body for the issues and time-entries
    lists. Binds to the conventional property names both Volt components
    declare: newQueryName / newQueryVisibility / newQueryRoleIds, plus
    their canManagePublicQueries and availableRoles computeds. The
    visibility downgrade for users without manage_public_queries is
    enforced server-side in Query::resolveVisibility() — hiding the
    selector here is presentation only.
--}}
@props(['canManagePublicQueries', 'visibility', 'roles'])
<form wire:submit="saveQuery" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
    <input type="text" wire:model="newQueryName" placeholder="クエリ名" class="rounded-md border-gray-300 text-sm">

    @if ($canManagePublicQueries)
        <select wire:model.live="newQueryVisibility" class="rounded-md border-gray-300 text-sm">
            <option value="private">非公開</option>
            <option value="roles">特定ロールに公開</option>
            <option value="public">全員に公開</option>
        </select>

        @if ($visibility === 'roles')
            <span class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                @foreach ($roles as $role)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="newQueryRoleIds" value="{{ $role->id }}" class="rounded border-gray-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </span>
            @error('newQueryRoleIds') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        @endif
    @else
        <span class="text-xs text-gray-500">(非公開クエリとして保存されます)</span>
    @endif

    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">保存</button>
    @error('newQueryName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
</form>
