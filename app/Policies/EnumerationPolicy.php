<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enumeration;
use App\Models\User;

/**
 * Enumerations (issue priorities, time entry activities, document
 * categories) are a site-wide administrative resource — only
 * administrators may manage them, enforced entirely via the Gate::before
 * admin bypass in AppServiceProvider; every method here denies by default
 * for everyone else.
 */
final class EnumerationPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Enumeration $enumeration): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Enumeration $enumeration): bool
    {
        return false;
    }

    public function delete(User $user, Enumeration $enumeration): bool
    {
        return false;
    }
}
