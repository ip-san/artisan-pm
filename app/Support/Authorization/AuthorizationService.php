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
 *
 * Bound as a scoped instance (PermissionServiceProvider), so the issue
 * visibility memo below lives for one request or job. Any write query in
 * that request flushes it (see flushCache()), so a membership, role, group
 * or project change made earlier in the same request is always seen.
 */
final class AuthorizationService
{
    /**
     * @var array<string, array<string, list<int>|null>>
     */
    private array $issueVisibilityRulesCache = [];

    /**
     * @var array<string, Collection<int, Project>>
     */
    private array $issueProjectsCache = [];

    /**
     * @var array<string, Collection<int, Role>> keyed "userId:projectId"
     */
    private array $memberRolesCache = [];

    /**
     * @var array<int, Collection<int, int>> keyed by user id
     */
    private array $groupIdsCache = [];

    /**
     * @var array<string, Collection<int, Role>> keyed by RoleBuiltin value
     */
    private array $builtinRolesCache = [];

    public function __construct(
        private readonly PermissionRegistry $permissions,
    ) {}

    public function can(?User $user, string $permissionKey, ?Project $project = null): bool
    {
        if ($user?->is_admin) {
            return true;
        }

        if ($project === null || ! $this->projectAllows($permissionKey, $project)) {
            return false;
        }

        return $this->rolesFor($user, $project)
            ->contains(fn (Role $role) => $role->hasPermission($permissionKey));
    }

    /**
     * Matches Redmine's Project#allows_to?: archived projects allow no
     * action at all, and a closed project allows only permissions flagged
     * read-only (`:read => true` in Redmine's preparation.rb) — add_issues
     * and manage_members are blocked, while view_* and the project
     * administration permissions Redmine flags as read (edit_project,
     * close_project, delete_project, select_project_modules) stay usable,
     * so a closed project can still be reopened, reconfigured or deleted.
     * A permission of a disabled module is not allowed either.
     */
    private function projectAllows(string $permissionKey, Project $project): bool
    {
        $permission = $this->permissions->get($permissionKey);

        if ($permission === null || $project->isArchived()) {
            return false;
        }

        if ($project->isClosed() && ! $permission->readOnly) {
            return false;
        }

        return $permission->module === null || $project->hasModule($permission->module);
    }

    /**
     * The trackers on which the user holds an issue permission in the
     * project (Redmine's Role#permissions_all_trackers? /
     * permissions_tracker_ids, unioned over every role the user has there —
     * own, group-derived or inherited — as Issue.allowed_target_trackers and
     * user_tracker_permission? do): null means every tracker, an empty
     * collection means none (including when can() is false).
     *
     * @return Collection<int, int>|null
     */
    public function allowedTrackerIds(?User $user, Project $project, string $permissionKey): ?Collection
    {
        if ($user?->is_admin) {
            return null;
        }

        if (! $this->projectAllows($permissionKey, $project)) {
            return collect();
        }

        $trackerIds = collect();

        foreach ($this->rolesFor($user, $project) as $role) {
            $roleTrackerIds = $role->trackerIdsFor($permissionKey);

            if ($roleTrackerIds === null) {
                return null;
            }

            $trackerIds = $trackerIds->merge($roleTrackerIds);
        }

        return $trackerIds->unique()->values();
    }

    /**
     * Redmine's Issue#user_tracker_permission?: can() narrowed to the roles
     * that grant the permission on this tracker.
     */
    public function canOnTracker(?User $user, string $permissionKey, Project $project, int $trackerId): bool
    {
        $trackerIds = $this->allowedTrackerIds($user, $project, $permissionKey);

        return $trackerIds === null || $trackerIds->contains($trackerId);
    }

    /**
     * What the user may see of the project's issues, as Redmine's
     * Issue.visible_condition builds it: one rule per issues_visibility tier
     * among the roles that hold view_issues, each limited to the trackers
     * those roles allow view_issues on (null = every tracker). An issue is
     * visible when any rule matches it; an empty array means no issue is.
     *
     * @return array<string, list<int>|null> keyed by IssueVisibility value
     */
    public function issueVisibilityRules(?User $user, Project $project): array
    {
        if ($user?->is_admin) {
            return [IssueVisibility::All->value => null];
        }

        // is_public and status are read from the instance, so an unsaved
        // change to them never reuses a rule set built without it.
        $key = implode(':', [$user?->id ?? 'anonymous', $project->id, (int) $project->is_public, $project->status?->value ?? '']);

        if ($project->id === null) {
            return $this->resolveIssueVisibilityRules($user, $project);
        }

        return $this->issueVisibilityRulesCache[$key] ??= $this->resolveIssueVisibilityRules($user, $project);
    }

    /**
     * issueVisibilityRules() for many projects at once, keyed by project id:
     * the user's memberships in all of them are loaded in one query rather
     * than one per project.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, list<int>|null>>
     */
    public function issueVisibilityRulesByProject(?User $user, Collection $projects): array
    {
        if ($user !== null && ! $user->is_admin) {
            $this->prefetchMemberRoles($user, $projects);
        }

        $rulesByProject = [];

        foreach ($projects as $project) {
            $rulesByProject[$project->id] = $this->issueVisibilityRules($user, $project);
        }

        return $rulesByProject;
    }

    /**
     * The projects Issue::scopeVisible() looks through for $user (every
     * project they can see, as visibleProjectIds() resolves it), with their
     * modules loaded, memoized like issueVisibilityRules().
     *
     * @return Collection<int, Project>
     */
    public function issueProjects(?User $user): Collection
    {
        $key = $user === null ? 'anonymous' : $user->id.':'.(int) $user->is_admin;

        return $this->issueProjectsCache[$key] ??= Project::query()
            ->whereIn('id', $this->visibleProjectIds($user))
            ->with('moduleAssignments')
            ->get();
    }

    /**
     * Forgets the memoized issue visibility. Called on every write query
     * and rolled-back transaction (PermissionServiceProvider): anything
     * from a new member or role to a module switch can change the rules,
     * and several of those writes (pivot attaches) fire no model event.
     */
    public function flushCache(): void
    {
        $this->issueVisibilityRulesCache = [];
        $this->issueProjectsCache = [];
        $this->memberRolesCache = [];
        $this->groupIdsCache = [];
        $this->builtinRolesCache = [];
    }

    /**
     * @return array<string, list<int>|null>
     */
    private function resolveIssueVisibilityRules(?User $user, Project $project): array
    {
        if (! $this->projectAllows('view_issues', $project)) {
            return [];
        }

        $rules = [];

        foreach ($this->rolesFor($user, $project) as $role) {
            if (! $role->hasPermission('view_issues')) {
                continue;
            }

            $tier = $role->issues_visibility->value;

            if (array_key_exists($tier, $rules) && $rules[$tier] === null) {
                continue;
            }

            $trackerIds = $role->trackerIdsFor('view_issues');
            $rules[$tier] = $trackerIds === null
                ? null
                : array_values(array_unique([...($rules[$tier] ?? []), ...$trackerIds]));
        }

        if ($user === null) {
            return $this->anonymousIssueVisibilityRules($rules);
        }

        // Every tier includes what "own" shows and "default" adds only
        // public issues, so the broadest tier over every tracker makes
        // the narrower ones redundant.
        if (array_key_exists(IssueVisibility::All->value, $rules) && $rules[IssueVisibility::All->value] === null) {
            return [IssueVisibility::All->value => null];
        }

        ksort($rules);

        return $rules;
    }

    /**
     * Redmine's visible_condition / visible? ignore issues_visibility for a
     * visitor who isn't logged in and show only public issues, whatever the
     * Anonymous role's tier. Every tier therefore collapses into "default"
     * (public issues; an anonymous visitor is never an author or assignee)
     * over the union of the tiers' trackers.
     *
     * @param  array<string, list<int>|null>  $rules
     * @return array<string, list<int>|null>
     */
    private function anonymousIssueVisibilityRules(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $trackerIds = [];

        foreach ($rules as $tierTrackerIds) {
            if ($tierTrackerIds === null) {
                return [IssueVisibility::Default->value => null];
            }

            $trackerIds = [...$trackerIds, ...$tierTrackerIds];
        }

        return [IssueVisibility::Default->value => array_values(array_unique($trackerIds))];
    }

    /**
     * A permission that belongs to no project (Redmine's
     * `allowed_to?(perm, nil, global: true)`, e.g. add_project): held when
     * any role the user has grants it — a role on any project membership
     * (directly or through a group) or the NonMember builtin role that every
     * signed-in user has. Anonymous visitors never hold one.
     */
    public function canGlobally(?User $user, string $permissionKey): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        if ($this->permissions->get($permissionKey) === null) {
            return false;
        }

        $granting = Role::query()->get()->filter(fn (Role $role) => $role->hasPermission($permissionKey));

        if ($granting->contains(fn (Role $role) => $role->builtin === RoleBuiltin::NonMember)) {
            return true;
        }

        return $this->hasAnyMembershipWithRoles($user, $granting->pluck('id'));
    }

    /**
     * Whether the user holds a membership in the project, directly or
     * through a group — Redmine's User#member_of?.
     */
    public function isMemberOf(User $user, Project $project): bool
    {
        return $this->memberRolesFor($user, $project)->isNotEmpty();
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
            return $project->is_public ? $this->builtinRoles(RoleBuiltin::Anonymous) : collect();
        }

        $memberRoles = $this->memberRolesFor($user, $project);

        if ($memberRoles->isNotEmpty()) {
            return $memberRoles;
        }

        return $project->is_public ? $this->builtinRoles(RoleBuiltin::NonMember) : collect();
    }

    /**
     * @return Collection<int, Role>
     */
    private function builtinRoles(RoleBuiltin $builtin): Collection
    {
        return $this->builtinRolesCache[$builtin->value] ??= Role::query()->where('builtin', $builtin)->get();
    }

    /**
     * The most permissive time_entries_visibility across every role a
     * member holds in this project (All wins over Own).
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
     * With $permission, a membership only counts when one of its roles
     * grants that permission. The project list passes `view_project` so the
     * ids match ProjectPolicy::view exactly (public, or a member whose role
     * holds view_project) and never show a project the policy would refuse.
     *
     * @return Collection<int, int>
     */
    public function visibleProjectIds(?User $user, ?string $permission = null): Collection
    {
        if ($user?->is_admin) {
            return Project::query()->pluck('id');
        }

        $groupIds = $user === null ? collect() : $user->groups()->pluck('groups.id');
        $grantingRoleIds = $permission === null
            ? null
            : Role::query()->get()->filter(fn (Role $role) => $role->hasPermission($permission))->pluck('id');

        return Project::query()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->where(function ($query) use ($user, $groupIds, $grantingRoleIds) {
                $query->where('is_public', true);

                if ($user !== null && ($grantingRoleIds === null || $grantingRoleIds->isNotEmpty())) {
                    $query->orWhereHas('members', function ($member) use ($user, $groupIds, $grantingRoleIds) {
                        $member->where(fn ($principal) => $principal->where('user_id', $user->id)->orWhereIn('group_id', $groupIds));

                        if ($grantingRoleIds !== null) {
                            $member->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $grantingRoleIds));
                        }
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
        // A project being created has no members yet.
        if ($project->id === null) {
            return (new Role)->newCollection();
        }

        if (! isset($this->memberRolesCache[$user->id.':'.$project->id])) {
            $this->prefetchMemberRoles($user, collect([$project]));
        }

        return $this->memberRolesCache[$user->id.':'.$project->id];
    }

    /**
     * Loads the roles the user holds in each of $projects (directly or
     * through a group, inherited ones included) with one query, into the
     * memo memberRolesFor() reads.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function prefetchMemberRoles(User $user, Collection $projects): void
    {
        $projectIds = $projects->pluck('id')
            ->filter()
            ->reject(fn (int $projectId) => isset($this->memberRolesCache[$user->id.':'.$projectId]))
            ->unique()
            ->values();

        if ($projectIds->isEmpty()) {
            return;
        }

        $groupIds = $this->groupIdsCache[$user->id] ??= $user->groups()->pluck('groups.id');

        $members = Member::query()
            ->whereIn('project_id', $projectIds)
            ->where(fn ($member) => $member->where('user_id', $user->id)->orWhereIn('group_id', $groupIds))
            ->with('roles')
            ->get()
            ->groupBy('project_id');

        foreach ($projectIds as $projectId) {
            $roles = collect($members->get($projectId) ?? [])
                ->flatMap(fn (Member $member) => $member->roles)
                ->unique('id')
                ->sortBy('id')
                ->values();

            $this->memberRolesCache[$user->id.':'.$projectId] = (new Role)->newCollection($roles->all());
        }
    }
}
