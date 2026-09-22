{{--
    The "Projects" panel of a user's or group's edit page: current
    memberships with their roles, and a form to add or change one. Drives
    the surrounding component's ManagesPrincipalMemberships methods.
--}}
@props(['memberships', 'projects', 'roles', 'editing' => null])

<section class="mt-8 rounded-md border border-neutral-200 bg-white p-4" data-principal-memberships>
    <h2 class="mb-3 text-sm font-semibold text-neutral-900">プロジェクト</h2>

    <ul class="mb-4 divide-y divide-neutral-100 text-sm">
        @forelse ($memberships as $member)
            <li class="flex items-center justify-between py-2" wire:key="principal-membership-{{ $member->id }}">
                <span>
                    <span class="font-medium text-neutral-900">{{ $member->project->name }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $member->roles->pluck('name')->join(', ') }}</span>
                </span>
                <span class="flex gap-3">
                    <button type="button" wire:click="editMembership({{ $member->id }})" class="text-brand-bold hover:underline">編集</button>
                    <button type="button" wire:click="removeMembership({{ $member->id }})" wire:confirm="このプロジェクトから外しますか?" class="text-danger-bolder hover:underline">削除</button>
                </span>
            </li>
        @empty
            <li class="py-2 text-neutral-500">所属するプロジェクトはありません。</li>
        @endforelse
    </ul>

    @if ($editing !== null || $projects->isNotEmpty())
        <div class="space-y-3 border-t border-neutral-100 pt-3">
            @if ($editing === null)
                <div>
                    <label class="block text-xs font-medium text-neutral-700">プロジェクトを追加</label>
                    <select wire:model="membershipProjectId" class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                        <option value="">選択してください</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                    @error('membershipProjectId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            @else
                <p class="text-xs font-medium text-neutral-700">「{{ $editing->project->name }}」のロールを変更</p>
            @endif

            <div class="flex flex-wrap gap-3 text-sm text-neutral-700">
                @foreach ($roles as $role)
                    <label class="flex items-center gap-1.5">
                        <input type="checkbox" value="{{ $role->id }}" wire:model="membershipRoleIds" class="rounded border-neutral-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>
            @error('membershipRoleIds') <p class="text-sm text-danger-bolder">{{ $message }}</p> @enderror

            <div class="flex gap-2">
                <button type="button" wire:click="saveMembership" class="rounded-md bg-brand-bold px-3 py-1.5 text-sm font-medium text-white hover:bg-brand">
                    {{ $editing === null ? '追加' : '更新' }}
                </button>
                @if ($editing !== null)
                    <button type="button" wire:click="cancelMembershipEdit" class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50">キャンセル</button>
                @endif
            </div>
        </div>
    @endif
</section>
