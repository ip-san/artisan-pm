<?php

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Enums\UsersVisibility;
use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Role;
use App\Models\Tracker;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Role $role = null;

    public string $name = '';

    /** @var array<string> */
    public array $permissions = [];

    public string $issuesVisibility = 'all';

    public string $timeEntriesVisibility = 'all';

    public string $usersVisibility = 'all';

    public bool $assignable = true;

    public ?int $defaultTimeEntryActivityId = null;

    public bool $allRolesManaged = true;

    /** @var array<int> */
    public array $managedRoleIds = [];

    /**
     * Redmine's role[permissions_all_trackers]: per issue permission,
     * whether it applies to every tracker.
     *
     * @var array<string, bool>
     */
    public array $permissionsAllTrackers = [];

    /**
     * Redmine's role[permissions_tracker_ids]: per issue permission, the
     * trackers it is limited to when not all trackers.
     *
     * @var array<string, array<int|string>>
     */
    public array $permissionTrackerIds = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->permissions = $role->permissionKeys();
            $this->issuesVisibility = $role->issues_visibility->value;
            $this->timeEntriesVisibility = $role->time_entries_visibility->value;
            $this->usersVisibility = $role->users_visibility->value;
            $this->assignable = $role->assignable;
            $this->defaultTimeEntryActivityId = $role->default_time_entry_activity_id;
            $this->allRolesManaged = $role->all_roles_managed;
            $this->managedRoleIds = $role->managedRoles->pluck('id')->all();
            $this->fillTrackerPermissions($role);
        } else {
            $this->authorize('create', Role::class);

            $this->fillTrackerPermissions(null);
            $this->prefillFromCopySource();
        }
    }

    /**
     * Seeds the permission × tracker table from a role (or "all trackers"
     * for a new one).
     */
    private function fillTrackerPermissions(?Role $role): void
    {
        foreach (Role::trackerPermissionKeys() as $permission) {
            $limited = $role !== null && $role->hasPermission($permission) && ! $role->permissionsAllTrackers($permission);
            $this->permissionsAllTrackers[$permission] = ! $limited;
            $this->permissionTrackerIds[$permission] = $limited ? $role->permissionTrackerIds($permission) : [];
        }
    }

    /**
     * ?copy_from=<id> seeds a new role's name/permissions from an existing
     * one — the name gets a distinct suffix so it doesn't collide with the
     * source's own unique name if left unedited, and builtin is never
     * copied (this form never sets it at all, even for a fresh role).
     */
    private function prefillFromCopySource(): void
    {
        $sourceId = request()->integer('copy_from');

        if ($sourceId === 0) {
            return;
        }

        $source = Role::find($sourceId);

        if ($source === null) {
            return;
        }

        $this->name = __(':name のコピー', ['name' => $source->name]);
        $this->permissions = $source->permissionKeys();
        $this->issuesVisibility = $source->issues_visibility->value;
        $this->timeEntriesVisibility = $source->time_entries_visibility->value;
        $this->usersVisibility = $source->users_visibility->value;
        $this->assignable = $source->assignable;
        $this->defaultTimeEntryActivityId = $source->default_time_entry_activity_id;
        $this->allRolesManaged = $source->all_roles_managed;
        $this->managedRoleIds = $source->managedRoles->pluck('id')->all();
        $this->fillTrackerPermissions($source);
    }

    /**
     * @return array<string>
     */
    #[Computed]
    public function availablePermissions(): array
    {
        $registry = app(PermissionRegistry::class);

        $isAnonymous = $this->role?->builtin === RoleBuiltin::Anonymous;
        $isNonMember = $this->role?->builtin === RoleBuiltin::NonMember;

        return array_keys($registry->assignableTo($isAnonymous, $isNonMember));
    }

    /**
     * Shared (non-project-specific) active time entry activities — the
     * choices for a role's default activity. Matches Redmine's
     * TimeEntryActivity.active.shared in roles/_form.html.erb.
     *
     * @return Collection<int, Enumeration>
     */
    #[Computed]
    public function sharedActivities(): Collection
    {
        return Enumeration::query()
            ->ofType(EnumerationType::TimeEntryActivity)
            ->whereNull('project_id')
            ->where('active', true)
            ->orderBy('position')
            ->get();
    }

    /**
     * Every other custom (non-builtin) role — candidates for "管理可能
     * ロール" when this role doesn't manage all of them. Matches Redmine's
     * Role.givable scope.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function otherGivableRoles(): Collection
    {
        return Role::query()
            ->givable()
            ->when($this->role, fn ($query) => $query->whereKeyNot($this->role->id))
            ->get();
    }

    /**
     * Every tracker, in order — the rows of the permission × tracker table.
     *
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    /**
     * The issue permissions this role can hold that can be limited to
     * trackers — the table's columns.
     *
     * @return list<string>
     */
    #[Computed]
    public function trackerPermissions(): array
    {
        return array_values(array_intersect(Role::trackerPermissionKeys(), $this->availablePermissions));
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->role?->id)],
            'issuesVisibility' => ['required', Rule::enum(IssueVisibility::class)],
            'timeEntriesVisibility' => ['required', Rule::enum(TimeEntryVisibility::class)],
            'usersVisibility' => ['required', Rule::enum(UsersVisibility::class)],
            'defaultTimeEntryActivityId' => ['nullable', Rule::in($this->sharedActivities->pluck('id')->all())],
            'managedRoleIds' => ['array'],
            'managedRoleIds.*' => [Rule::in($this->otherGivableRoles->pluck('id')->all())],
        ]);

        $data['permissions'] = ($this->role ?? new Role)->withUnregisteredPermissions(array_values(array_intersect($this->permissions, $this->availablePermissions)));
        $data['issues_visibility'] = $data['issuesVisibility'];
        $data['time_entries_visibility'] = $data['timeEntriesVisibility'];
        $data['users_visibility'] = $data['usersVisibility'];
        $data['assignable'] = $this->assignable;
        // The Anonymous role never logs time, so Redmine hides the field for it.
        $data['default_time_entry_activity_id'] = $this->role?->builtin === RoleBuiltin::Anonymous
            ? null
            : $data['defaultTimeEntryActivityId'];
        $data['all_roles_managed'] = $this->allRolesManaged;
        unset($data['issuesVisibility'], $data['timeEntriesVisibility'], $data['usersVisibility'], $data['managedRoleIds'], $data['defaultTimeEntryActivityId']);

        $role = $this->role ?? new Role;
        $trackerIds = $this->trackers->pluck('id')->all();

        foreach (Role::trackerPermissionKeys() as $permission) {
            $role->setPermissionTrackers($permission, ($this->permissionsAllTrackers[$permission] ?? true)
                ? null
                : array_values(array_intersect(array_map('intval', $this->permissionTrackerIds[$permission] ?? []), $trackerIds)));
        }

        $data['settings'] = $role->settings;

        if ($this->role) {
            $this->role->update($data);
        } else {
            $data['position'] = Role::query()->max('position') + 1;
            $this->role = Role::create($data);
        }

        // Only meaningful when all_roles_managed is off, but kept in sync
        // regardless so toggling it back on later doesn't resurrect a
        // stale subset from before.
        $this->role->managedRoles()->sync($data['all_roles_managed'] ? [] : $this->managedRoleIds);

        $this->redirect(route('roles.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">
        {{ $role ? __('ロールを編集') : __('新規ロール') }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="field-name" class="block text-sm font-medium text-neutral-700">{{ __('名前') }}</label>
            <input id="field-name" type="text" wire:model="name" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        {{-- A visitor who isn't logged in only ever sees public issues (Redmine hides this for the Anonymous role). --}}
        @if ($role?->builtin !== \App\Enums\RoleBuiltin::Anonymous)
            <div>
                <label for="field-issuesVisibility" class="block text-sm font-medium text-neutral-700">{{ __('課題の閲覧範囲') }}</label>
                <select id="field-issuesVisibility" wire:model="issuesVisibility" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="all">{{ __('すべての課題') }}</option>
                    <option value="default">{{ __('デフォルト') }}</option>
                    <option value="own">{{ __('自分が作成または担当する課題のみ') }}</option>
                </select>
                @error('issuesVisibility') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <label for="field-timeEntriesVisibility" class="block text-sm font-medium text-neutral-700">{{ __('工数の閲覧範囲') }}</label>
            <select id="field-timeEntriesVisibility" wire:model="timeEntriesVisibility" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="all">{{ __('すべての工数') }}</option>
                <option value="default">{{ __('デフォルト') }}</option>
                <option value="own">{{ __('自分の工数のみ') }}</option>
            </select>
            @error('timeEntriesVisibility') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="field-usersVisibility" class="block text-sm font-medium text-neutral-700">{{ __('ユーザーの閲覧範囲') }}</label>
            <select id="field-usersVisibility" wire:model="usersVisibility" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="all">{{ __('すべてのアクティブなユーザー') }}</option>
                <option value="members_of_visible_projects">{{ __('閲覧可能なプロジェクトのメンバーのみ') }}</option>
            </select>
            @error('usersVisibility') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('このロールを持つメンバーが、プロジェクトメンバー追加時のユーザー検索でどこまでの範囲のユーザーを検索できるかを制限します。') }}
            </p>
        </div>

        @if ($role?->builtin !== \App\Enums\RoleBuiltin::Anonymous)
            <div>
                <label for="field-defaultTimeEntryActivityId" class="block text-sm font-medium text-neutral-700">{{ __('既定の作業分類') }}</label>
                <select id="field-defaultTimeEntryActivityId" wire:model="defaultTimeEntryActivityId" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('なし') }}</option>
                    @foreach ($this->sharedActivities as $activity)
                        <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                    @endforeach
                </select>
                @error('defaultTimeEntryActivityId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('このロールを持つメンバーが工数を記録するとき、作業分類の初期値になります。') }}
                </p>
            </div>
        @endif

        <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model="assignable" class="rounded border-neutral-300">
            {{ __('このロールを持つメンバーを課題の担当者として選択可能にする') }}
        </label>

        <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model.live="allRolesManaged" class="rounded border-neutral-300">
            {{ __('このロールを持つメンバーはプロジェクトメンバーのすべてのロールを管理できる') }}
        </label>

        @unless ($allRolesManaged)
            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('管理可能ロール(メンバー管理画面で割当/削除できるロール)') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->otherGivableRoles as $candidate)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="managedRoleIds" value="{{ $candidate->id }}" class="rounded border-neutral-300">
                            {{ $candidate->name }}
                        </label>
                    @endforeach
                </div>
                @error('managedRoleIds') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div>
            <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('権限') }}</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($this->availablePermissions as $permission)
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model.live="permissions" value="{{ $permission }}" class="rounded border-neutral-300">
                        {{ $permission }}
                    </label>
                @endforeach
            </div>
        </div>

        @if ($this->trackerPermissions !== [])
            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('課題トラッキング') }}</span>
                <p class="mb-2 text-xs text-neutral-500">
                    {{ __('課題の権限ごとに、対象とするトラッカーを限定できます。') }}
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" data-testid="role-tracker-permissions">
                        <thead>
                            <tr class="border-b border-neutral-200">
                                <th class="px-2 py-1 text-left font-medium text-neutral-700">{{ __('トラッカー') }}</th>
                                @foreach ($this->trackerPermissions as $permission)
                                    <th class="px-2 py-1 text-center font-medium text-neutral-700 {{ in_array($permission, $permissions, true) ? '' : 'text-neutral-400' }}">{{ $permission }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-neutral-100">
                                <td class="px-2 py-1 font-semibold text-neutral-800">{{ __('全トラッカー') }}</td>
                                @foreach ($this->trackerPermissions as $permission)
                                    <td class="px-2 py-1 text-center">
                                        <input type="checkbox" wire:model.live="permissionsAllTrackers.{{ $permission }}"
                                            @disabled(! in_array($permission, $permissions, true))
                                            aria-label="{{ __('全トラッカー') }} {{ $permission }}"
                                            class="rounded border-neutral-300">
                                    </td>
                                @endforeach
                            </tr>
                            @foreach ($this->trackers as $tracker)
                                <tr class="border-b border-neutral-100">
                                    <td class="px-2 py-1 text-neutral-700">{{ $tracker->name }}</td>
                                    @foreach ($this->trackerPermissions as $permission)
                                        <td class="px-2 py-1 text-center">
                                            <input type="checkbox" wire:model="permissionTrackerIds.{{ $permission }}" value="{{ $tracker->id }}"
                                                @disabled(! in_array($permission, $permissions, true) || ($permissionsAllTrackers[$permission] ?? true))
                                                aria-label="{{ $tracker->name }} {{ $permission }}"
                                                class="rounded border-neutral-300">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="btn btn-primary">
                {{ __('保存') }}
            </button>
            <a href="{{ route('roles.index') }}" class="btn btn-secondary">
                {{ __('キャンセル') }}
            </a>
        </div>
    </form>
</div>
