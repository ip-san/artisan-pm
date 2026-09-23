<?php

use App\Models\Project;
use App\Models\Role;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /** @var array<int, array{name: string, identifier: string}> projects whose members hold the role a delete was refused for */
    public array $blockingProjects = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderBy('position')->get();
    }

    /**
     * Redmine's RolesController#destroy: a role in use is kept and the
     * error names the projects whose members hold it.
     */
    public function delete(int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $this->authorize('delete', $role);

        if (! $role->isDeletable()) {
            $this->blockingProjects = Project::query()
                ->whereHas('members.roles', fn ($query) => $query->where('roles.id', $role->id))
                ->orderBy('name')
                ->get(['id', 'name', 'identifier'])
                ->map(fn (Project $project) => ['name' => $project->name, 'identifier' => $project->identifier])
                ->all();
            $this->addError('delete', __('このロールは使用中です。削除できません。'));

            return;
        }

        $role->delete();
        $this->blockingProjects = [];
        $this->resetErrorBag();

        unset($this->roles);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('ロール管理') }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('roles.report') }}"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('権限レポート') }}
            </a>
            <a href="{{ route('roles.create') }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('新規ロール') }}
            </a>
        </div>
    </div>

    @error('delete')
        <div class="mb-4 rounded-md border border-danger-subtler bg-danger-subtlest px-4 py-3 text-sm text-danger-bolder" role="alert">
            <p>{{ $message }}</p>
            @if ($blockingProjects !== [])
                <p class="mt-1">
                    {{ __('以下のプロジェクトにこのロールのメンバーがいます:') }}
                    @foreach ($blockingProjects as $blockingProject)
                        <a href="{{ route('projects.members', $blockingProject['identifier']) }}" class="underline" wire:key="blocking-{{ $blockingProject['identifier'] }}">{{ $blockingProject['name'] }}</a>@unless ($loop->last), @endunless
                    @endforeach
                </p>
            @endif
        </div>
    @enderror

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @foreach ($this->roles as $role)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $role->name }}</span>
                    @if ($role->builtin)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ $role->builtin->value }}</span>
                    @endif
                    <span class="ml-2 text-xs text-neutral-500">{{ __(':count 権限', ['count' => count($role->permissionKeys())]) }}</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('roles.edit', $role) }}" class="text-sm text-brand-bold hover:underline">{{ __('編集') }}</a>
                    <a href="{{ route('roles.create') }}?copy_from={{ $role->id }}" class="text-sm text-brand-bold hover:underline">{{ __('コピー') }}</a>
                    @unless ($role->builtin)
                        <button wire:click="delete({{ $role->id }})" wire:confirm="{{ __('このロールを削除しますか?') }}"
                            class="text-sm text-danger-bolder hover:underline">{{ __('削除') }}</button>
                    @endunless
                </div>
            </li>
        @endforeach
    </ul>
</div>
