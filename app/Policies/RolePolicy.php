<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Roles are a site-wide (not per-project) administrative resource in this
 * system, so only administrators may manage them — enforced entirely via
 * the Gate::before admin bypass in AppServiceProvider; every method here
 * denies by default for everyone else.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Role $role): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return false;
    }

    public function delete(User $user, Role $role): bool
    {
        return false;
    }
}
