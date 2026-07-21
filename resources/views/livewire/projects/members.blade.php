<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public string $email = '';

    /** @var array<int> */
    public array $roleIds = [];

    public function mount(Project $project): void
    {
        $this->authorize('manageMembers', $project);

        $this->project = $project;
    }

    public function getRolesProperty(): Collection
    {
        return Role::query()->whereNull('builtin')->orderBy('position')->get();
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->project);

        $data = $this->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'roleIds' => ['required', 'array', 'min:1'],
            'roleIds.*' => ['exists:roles,id'],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        $member = Member::query()
            ->firstOrCreate(['project_id' => $this->project->id, 'user_id' => $user->id]);

        $member->roles()->sync($data['roleIds']);

        $this->reset('email', 'roleIds');
        $this->project->unsetRelation('members');
    }

    public function removeMember(int $memberId): void
    {
        $this->authorize('manageMembers', $this->project);

        Member::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($memberId)
            ->delete();

        $this->project->unsetRelation('members');
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — メンバー管理</h1>

    <form wire:submit="addMember" class="mb-8 space-y-3 rounded-md border border-gray-200 bg-white p-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">ユーザーのメールアドレス</label>
            <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

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

        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            メンバーを追加
        </button>
    </form>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($project->members()->with(['user', 'group', 'roles'])->get() as $member)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">
                        {{ $member->isForGroup() ? $member->group->name.'(グループ)' : $member->user->name }}
                    </span>
                    <span class="ml-2 text-xs text-gray-500">
                        {{ $member->roles->pluck('name')->join(', ') }}
                    </span>
                </div>
                <button wire:click="removeMember({{ $member->id }})" wire:confirm="このメンバーを削除しますか?"
                    class="text-sm text-red-600 hover:underline">
                    削除
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">メンバーがいません。</li>
        @endforelse
    </ul>
</div>
