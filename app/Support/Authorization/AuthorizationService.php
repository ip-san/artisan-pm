<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "can this user do this permission, optionally
 * scoped to a project". Policies delegate here rather than re-implementing
 * role/module resolution themselves.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly PermissionRegistry $permissions,
    ) {}

    public function can(?User $user, string $permissionKey, ?Project $project = null): bool
    {
        if ($user?->is_admin) {
            return true;
        }

        $permission = $this->permissions->get($permissionKey);

        if ($permission === null) {
            return false;
        }

        if ($project === null) {
            return false;
        }

        // Matches Redmine's Project#allows_to?: archived projects allow no
        // action at all. Closed projects allow only read-only module
        // permissions (e.g. add_issues is blocked) — project-management
        // permissions (module === null, like close_project/edit_project)
        // are deliberately exempt so a closed project can still be
        // reopened or otherwise administered.
        if ($project->isArchived()) {
            return false;
        }

        if ($project->isClosed() && $permission->module !== null && ! $permission->readOnly) {
            return false;
        }

        if ($permission->module !== null && ! $project->hasModule($permission->module)) {
            return false;
        }

        return $this->rolesFor($user, $project)
            ->contains(fn (Role $role) => $role->hasPermission($permissionKey));
    }

    /**
     * Resolves in tiers: guests get the Anonymous builtin role on public
     * projects; members get their assigned role(s); everyone else falls
     * back to the NonMember builtin role, again only on public projects.
     *
     * @return Collection<int, Role>
     */
    public function rolesFor(?User $user, Project $project): Collection
    {
        if ($user === null) {
            return $project->is_public
                ? Role::query()->where('builtin', RoleBuiltin::Anonymous)->get()
                : collect();
        }

        $memberRoles = $this->memberRolesFor($user, $project);

        if ($memberRoles->isNotEmpty()) {
            return $memberRoles;
        }

        return $project->is_public
            ? Role::query()->where('builtin', RoleBuiltin::NonMember)->get()
            : collect();
    }

    /**
     * The most permissive issues_visibility across every role a user holds
     * in this project (All > Default > Own) — uses rolesFor() rather than
     * memberRolesFor() directly so guests/non-members correctly consult
     * their builtin role's own setting instead of always resolving to All.
     */
    public function issueVisibilityFor(?User $user, Project $project): IssueVisibility
    {
        if ($user?->is_admin) {
            return IssueVisibility::All;
        }

        $roles = $this->rolesFor($user, $project);

        if ($roles->isEmpty()) {
            return IssueVisibility::All;
        }

        if ($roles->contains(fn (Role $role) => $role->issues_visibility === IssueVisibility::All)) {
            return IssueVisibility::All;
        }

        if ($roles->contains(fn (Role $role) => $role->issues_visibility === IssueVisibility::Default)) {
            return IssueVisibility::Default;
        }

        return IssueVisibility::Own;
    }

    /**
     * Same broadest-wins resolution as issueVisibilityFor(), for
     * time_entries_visibility.
     */
    public function timeEntryVisibilityFor(?User $user, Project $project): TimeEntryVisibility
    {
        if ($user?->is_admin) {
            return TimeEntryVisibility::All;
        }

        $memberRoles = $user === null ? collect() : $this->memberRolesFor($user, $project);

        if ($memberRoles->isEmpty()) {
            return TimeEntryVisibility::All;
        }

        $broadest = $memberRoles->first(fn (Role $role) => $role->time_entries_visibility !== TimeEntryVisibility::Own);

        return $broadest !== null ? TimeEntryVisibility::All : TimeEntryVisibility::Own;
    }

    /**
     * The roles a user may assign to other members on this project's
     * members screen — matches Redmine's Member#managed_roles /
     * User#managed_roles(project). Among the user's own roles in the
     * project, only ones holding manage_members are considered; if any of
     * those has all_roles_managed, every givable (non-builtin) role is
     * returned, otherwise the union of their individually configured
     * managedRoles.
     *
     * @return Collection<int, Role>
     */
    public function managedRolesFor(?User $user, Project $project): Collection
    {
        if ($user?->is_admin) {
            return $this->givableRoles();
        }

        if ($user === null) {
            return collect();
        }

        $managingRoles = $this->memberRolesFor($user, $project)
            ->filter(fn (Role $role) => $role->hasPermission('manage_members'));

        if ($managingRoles->isEmpty()) {
            return collect();
        }

        if ($managingRoles->contains(fn (Role $role) => $role->all_roles_managed)) {
            return $this->givableRoles();
        }

        return $managingRoles->flatMap(fn (Role $role) => $role->managedRoles)
            ->unique('id')
            ->sortBy('position')
            ->values();
    }

    /**
     * @return Collection<int, Role>
     */
    private function givableRoles(): Collection
    {
        return Role::query()->whereNull('builtin')->orderBy('position')->get();
    }

    /**
     * @return Collection<int, Role>
     */
    private function memberRolesFor(User $user, Project $project): Collection
    {
        $groupIds = $user->groups()->pluck('groups.id');

        return Role::query()
            ->whereHas('members', function ($query) use ($user, $project, $groupIds) {
                $query->where('project_id', $project->id)
                    ->where(function ($member) use ($user, $groupIds) {
                        $member->where('user_id', $user->id)
                            ->orWhereIn('group_id', $groupIds);
                    });
            })
            ->get();
    }
}
