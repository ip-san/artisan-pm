<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * User administration is a site-wide resource — only administrators may
 * manage other accounts, enforced entirely via the Gate::before admin
 * bypass in AppServiceProvider; every method here denies by default for
 * everyone else. A user's own account is managed separately, through
 * resources/views/livewire/profile/index.blade.php, which needs no policy
 * since it always acts on auth()->user().
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $target): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return false;
    }
}
