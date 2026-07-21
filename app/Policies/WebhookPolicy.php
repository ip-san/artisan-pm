<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;

/**
 * Webhooks are a site-wide administrative resource, and their secret is
 * used to sign every outbound request — only administrators may manage
 * them, enforced entirely via the Gate::before admin bypass in
 * AppServiceProvider; every method here denies by default for everyone else.
 */
final class WebhookPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return false;
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return false;
    }
}
