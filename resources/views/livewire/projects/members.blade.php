<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public string $addType = 'user';

    public string $userSearch = '';

    public ?int $selectedUserId = null;

    public bool $showUserDropdown = false;

    public ?int $groupId = null;

    /** @var array<int> */
    public array $roleIds = [];

    public ?int $editingMemberId = null;

    public function mount(Project $project): void
    {
        $this->authorize('manageMembers', $project);

        $this->project = $project;
    }

    public function editMember(int $memberId): void
    {
        $this->authorize('manageMembers', $this->project);

        $member = Member::query()->where('project_id', $this->project->id)->findOrFail($memberId);

        abort_if($member->isForGroup(), 404);

        $this->editingMemberId = $member->id;
        $this->selectedUserId = $member->user_id;
        $this->userSearch = "{$member->user->name} ({$member->user->email})";

        // Only the roles that actually have a checkbox (this editor's
        // managed set) are prefilled — a role outside that set stays
        // untouched via addMember()'s own logic regardless of what's
        // bound here, and including it would fail that same request's
        // Rule::in($managedRoleIds) validation on submit.
        $this->roleIds = $member->roles->pluck('id')->intersect($this->roles->pluck('id'))->all();
    }

    public function cancelEdit(): void
    {
        $this->reset('userSearch', 'selectedUserId', 'groupId', 'roleIds', 'editingMemberId');
    }

    public function updatedUserSearch(): void
    {
        $this->selectedUserId = null;
        $this->showUserDropdown = trim($this->userSearch) !== '';
    }

    public function selectUser(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->selectedUserId = $user->id;
        $this->userSearch = "{$user->name} ({$user->email})";
        $this->showUserDropdown = false;
    }

    /**
     * Name/email substring matches, excluding users already a member of
     * this project — matches Redmine's autocomplete_for_user (used by the
     * project members form) which likewise excludes existing members.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function userCandidates(): Collection
    {
        $search = trim($this->userSearch);

        if ($search === '' || $this->selectedUserId !== null) {
            return collect();
        }

        $existingUserIds = $this->project->members()->whereNotNull('user_id')->pluck('user_id');

        return User::query()
            ->whereNotIn('id', $existingUserIds)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * The roles offered as checkboxes — restricted to whichever ones the
     * current user is allowed to manage (Role::all_roles_managed /
     * managedRoles), matching Redmine's member-role-editing restriction.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return app(AuthorizationService::class)->managedRolesFor(auth()->user(), $this->project);
    }

    /**
     * Groups not already members of this project — offered in the "add a
     * group" selector.
     *
     * @return Collection<int, Group>
     */
    #[Computed]
    public function availableGroups(): Collection
    {
        $memberGroupIds = $this->project->members()->whereNotNull('group_id')->pluck('group_id');

        return Group::query()->whereNotIn('id', $memberGroupIds)->orderBy('name')->get();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->project->members()->with(['user', 'group', 'roles'])->get();
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->project);

        $managedRoleIds = $this->roles->pluck('id')->all();

        if ($this->addType === 'group') {
            $data = $this->validate([
                'groupId' => ['required', 'exists:groups,id'],
                'roleIds' => ['array'],
                'roleIds.*' => [Rule::in($managedRoleIds)],
            ]);

            $member = Member::query()
                ->firstOrCreate(['project_id' => $this->project->id, 'group_id' => $data['groupId']]);
        } else {
            $data = $this->validate([
                'selectedUserId' => ['required', 'exists:users,id'],
                'roleIds' => ['array'],
                'roleIds.*' => [Rule::in($managedRoleIds)],
            ]);

            $member = Member::query()
                ->firstOrCreate(['project_id' => $this->project->id, 'user_id' => $data['selectedUserId']]);
        }

        // Roles outside the editor's managed set are left untouched —
        // matches Redmine's Member#set_editable_role_ids, so someone who
        // can only manage a subset of roles can't silently strip a role
        // they have no authority over just because it wasn't offered as a
        // checkbox in the first place. "At least one role" is therefore
        // checked against this final combined set, not the raw submission
        // — an edit that leaves only untouched roles in place is valid
        // even though roleIds itself came back empty.
        $untouchedRoleIds = $member->roles->pluck('id')->diff($managedRoleIds);
        $touchedRoleIds = collect($data['roleIds'])->intersect($managedRoleIds);
        $finalRoleIds = $untouchedRoleIds->merge($touchedRoleIds);

        if ($finalRoleIds->isEmpty()) {
            $this->addError('roleIds', '少なくとも1つのロールを選択してください。');

            return;
        }

        $member->roles()->sync($finalRoleIds);

        $this->reset('userSearch', 'selectedUserId', 'groupId', 'roleIds', 'editingMemberId');
        unset($this->members, $this->availableGroups);
    }

    public function removeMember(int $memberId): void
    {
        $this->authorize('manageMembers', $this->project);

        Member::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($memberId)
            ->delete();

        unset($this->members);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — メンバー管理</h1>

    <form wire:submit="addMember" class="mb-8 space-y-3 rounded-md border border-gray-200 bg-white p-4">
        @unless ($editingMemberId)
            <div class="flex gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" wire:model.live="addType" value="user" class="border-gray-300">
                    ユーザー
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" wire:model.live="addType" value="group" class="border-gray-300">
                    グループ
                </label>
            </div>
        @endunless

        @if ($addType === 'group' && ! $editingMemberId)
            <div>
                <label class="block text-sm font-medium text-gray-700">グループ</label>
                <select wire:model="groupId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->availableGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
                @error('groupId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700">ユーザー</label>
                <input type="text" wire:model.live.debounce.300ms="userSearch" @disabled($editingMemberId !== null)
                    placeholder="名前またはメールアドレスで検索" autocomplete="off"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @if ($showUserDropdown)
                    <ul class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg">
                        @forelse ($this->userCandidates as $candidate)
                            <li wire:key="user-candidate-{{ $candidate->id }}">
                                <button type="button" wire:click="selectUser({{ $candidate->id }})"
                                    class="block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                    {{ $candidate->name }} ({{ $candidate->email }})
                                </button>
                            </li>
                        @empty
                            <li class="px-3 py-2 text-sm text-gray-500">該当するユーザーがいません。</li>
                        @endforelse
                    </ul>
                @endif
                @error('selectedUserId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-1">ロール</span>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->roles as $role)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded border-gray-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>
            @error('roleIds') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                {{ $editingMemberId ? 'ロールを更新' : 'メンバーを追加' }}
            </button>
            @if ($editingMemberId)
                <button type="button" wire:click="cancelEdit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    キャンセル
                </button>
            @endif
        </div>
    </form>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->members as $member)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">
                        {{ $member->isForGroup() ? $member->group->name.'(グループ)' : $member->user->name }}
                    </span>
                    <span class="ml-2 text-xs text-gray-500">
                        {{ $member->roles->pluck('name')->join(', ') }}
                    </span>
                </div>
                <div class="flex gap-3">
                    @unless ($member->isForGroup())
                        <button wire:click="editMember({{ $member->id }})" class="text-sm text-indigo-600 hover:underline">
                            編集
                        </button>
                    @endunless
                    <button wire:click="removeMember({{ $member->id }})" wire:confirm="このメンバーを削除しますか?"
                        class="text-sm text-red-600 hover:underline">
                        削除
                    </button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">メンバーがいません。</li>
        @endforelse
    </ul>
</div>
