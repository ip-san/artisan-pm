<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The workflow transition/field-rule matrix is a site-wide administrative
 * resource edited as one screen across two models (WorkflowTransition,
 * WorkflowFieldRule) rather than per-record CRUD — matching SettingPolicy's
 * shape, pegged to WorkflowTransition purely so Laravel's naming-convention
 * policy discovery has a model to resolve against. Only administrators may
 * manage it, enforced entirely via the Gate::before admin bypass in
 * AppServiceProvider; this denies by default for everyone else.
 */
final class WorkflowTransitionPolicy
{
    public function manage(User $user): bool
    {
        return false;
    }
}
