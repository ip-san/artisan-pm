<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\IssueVisibility;
use App\Enums\ProjectStatus;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Enums\UsersVisibility;
use App\Models\Member;
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
     * Whether a user holds any of the given roles on ANY project — used by
     * Query::visibleTo() for a project-less (global) query's Roles
     * visibility, matching Redmine's Query#visible? for a nil project
     * (`user.memberships.joins(:member_roles).where(role_id: roles)`, i.e.
     * membership in a single matching project anywhere is enough, unlike
     * the project-scoped case which intersects roles within one project).
     *
     * @param  Collection<int, int>  $roleIds
     */
    public function hasAnyMembershipWithRoles(User $user, Collection $roleIds): bool
    {
        if ($roleIds->isEmpty()) {
            return false;
        }

        $groupIds = $user->groups()->pluck('groups.id');

        return Member::query()
            ->where(function ($member) use ($user, $groupIds) {
                $member->where('user_id', $user->id)->orWhereIn('group_id', $groupIds);
            })
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->exists();
    }

    /**
     * Matches Redmine's Principal.visible scope (principal.rb): a user
     * whose *any* project membership carries a role with
     * users_visibility == 'all' — or, if they hold no membership
     * anywhere, whose builtin NonMember role does — can search/see every
     * active user site-wide (e.g. the "add member" autocomplete). Anyone
     * else is restricted to visibleProjectIds()'s members. Admins and
     * (for the null/guest case) the Anonymous builtin role are checked
     * the same way Redmine checks `user.admin?` and an anonymous
     * Principal.visible caller.
     */
    public function hasSiteWideUserVisibility(?User $user): bool
    {
        if ($user === null) {
            return Role::query()->where('builtin', RoleBuiltin::Anonymous)->value('users_visibility') === UsersVisibility::All;
        }

        if ($user->is_admin) {
            return true;
        }

        $groupIds = $user->groups()->pluck('groups.id');

        $hasAnyMembership = Member::query()
            ->where(function ($member) use ($user, $groupIds) {
                $member->where('user_id', $user->id)->orWhereIn('group_id', $groupIds);
            })
            ->exists();

        if (! $hasAnyMembership) {
            return Role::query()->where('builtin', RoleBuiltin::NonMember)->value('users_visibility') === UsersVisibility::All;
        }

        return Role::query()
            ->whereHas('members', function ($query) use ($user, $groupIds) {
                $query->where(function ($member) use ($user, $groupIds) {
                    $member->where('user_id', $user->id)->orWhereIn('group_id', $groupIds);
                });
            })
            ->where('users_visibility', UsersVisibility::All->value)
            ->exists();
    }

    /**
     * Non-archived projects this user can see the existence of: public
     * projects, plus any (private or public) project they're a member of
     * directly or via a group. Used to restrict who counts as "visible"
     * under users_visibility === members_of_visible_projects, matching
     * Redmine's User#visible_project_ids (Project.visible(self).pluck
     * (:id)) — this is a pragmatic approximation of Project.visible (the
     * same is_public-or-member simplification ProjectPolicy::view already
     * makes) rather than a full per-project policy check, since re-running
     * Gate::allows('view', ...) per project here would be a per-row policy
     * call for every project in the system.
     *
     * @return Collection<int, int>
     */
    public function visibleProjectIds(?User $user): Collection
    {
        if ($user?->is_admin) {
            return Project::query()->pluck('id');
        }

        $groupIds = $user === null ? collect() : $user->groups()->pluck('groups.id');

        return Project::query()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->where(function ($query) use ($user, $groupIds) {
                $query->where('is_public', true);

                if ($user !== null) {
                    $query->orWhereHas('members', function ($member) use ($user, $groupIds) {
                        $member->where('user_id', $user->id)->orWhereIn('group_id', $groupIds);
                    });
                }
            })
            ->pluck('id');
    }

    /**
     * @return Collection<int, Role>
     */
    private function givableRoles(): Collection
    {
        return Role::query()->givable()->get();
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
