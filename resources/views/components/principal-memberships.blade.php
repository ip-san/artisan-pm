{{--
    The "Projects" panel of a user's or group's edit page: current
    memberships with their roles, and a form to add or change one. Drives
    the surrounding component's ManagesPrincipalMemberships methods.
--}}
@props(['memberships', 'projects', 'roles', 'editing' => null])

<section class="mt-8 rounded-md border border-gray-200 bg-white p-4" data-principal-memberships>
    <h2 class="mb-3 text-sm font-semibold text-gray-900">プロジェクト</h2>

    <ul class="mb-4 divide-y divide-gray-100 text-sm">
        @forelse ($memberships as $member)
            <li class="flex items-center justify-between py-2" wire:key="principal-membership-{{ $member->id }}">
                <span>
                    <span class="font-medium text-gray-900">{{ $member->project->name }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $member->roles->pluck('name')->join(', ') }}</span>
                </span>
                <span class="flex gap-3">
                    <button type="button" wire:click="editMembership({{ $member->id }})" class="text-indigo-600 hover:underline">編集</button>
                    <button type="button" wire:click="removeMembership({{ $member->id }})" wire:confirm="このプロジェクトから外しますか?" class="text-red-600 hover:underline">削除</button>
                </span>
            </li>
        @empty
            <li class="py-2 text-gray-500">所属するプロジェクトはありません。</li>
        @endforelse
    </ul>

    @if ($editing !== null || $projects->isNotEmpty())
        <div class="space-y-3 border-t border-gray-100 pt-3">
            @if ($editing === null)
                <div>
                    <label class="block text-xs font-medium text-gray-700">プロジェクトを追加</label>
                    <select wire:model="membershipProjectId" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">選択してください</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                    @error('membershipProjectId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @else
                <p class="text-xs font-medium text-gray-700">「{{ $editing->project->name }}」のロールを変更</p>
            @endif

            <div class="flex flex-wrap gap-3 text-sm text-gray-700">
                @foreach ($roles as $role)
                    <label class="flex items-center gap-1.5">
                        <input type="checkbox" value="{{ $role->id }}" wire:model="membershipRoleIds" class="rounded border-gray-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>
            @error('membershipRoleIds') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex gap-2">
                <button type="button" wire:click="saveMembership" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                    {{ $editing === null ? '追加' : '更新' }}
                </button>
                @if ($editing !== null)
                    <button type="button" wire:click="cancelMembershipEdit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">キャンセル</button>
                @endif
            </div>
        </div>
    @endif
</section>
