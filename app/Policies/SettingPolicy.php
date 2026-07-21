<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Application settings are a site-wide administrative resource, edited as
 * one form rather than per-record CRUD — enforced entirely via the
 * Gate::before admin bypass in AppServiceProvider; this denies by default
 * for everyone else.
 */
final class SettingPolicy
{
    public function manage(User $user): bool
    {
        return false;
    }
}
