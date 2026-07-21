<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tracker;
use App\Models\User;

/**
 * Trackers are a site-wide administrative resource — only administrators
 * may manage them, enforced entirely via the Gate::before admin bypass in
 * AppServiceProvider; every method here denies by default for everyone else.
 */
final class TrackerPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Tracker $tracker): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Tracker $tracker): bool
    {
        return false;
    }

    public function delete(User $user, Tracker $tracker): bool
    {
        return false;
    }
}
