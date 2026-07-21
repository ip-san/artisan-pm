<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\RoleBuiltin;
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
