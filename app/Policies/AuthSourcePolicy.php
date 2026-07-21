<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuthSource;
use App\Models\User;

/**
 * LDAP auth sources are a site-wide administrative resource, and store
 * plaintext-equivalent bind credentials — only administrators may manage
 * them, enforced entirely via the Gate::before admin bypass in
 * AppServiceProvider; every method here denies by default for everyone else.
 */
final class AuthSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, AuthSource $authSource): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuthSource $authSource): bool
    {
        return false;
    }

    public function delete(User $user, AuthSource $authSource): bool
    {
        return false;
    }
}
