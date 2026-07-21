<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IssueStatus;
use App\Models\User;

/**
 * Issue statuses are a site-wide administrative resource — only
 * administrators may manage them, enforced entirely via the Gate::before
 * admin bypass in AppServiceProvider; every method here denies by default
 * for everyone else.
 */
final class IssueStatusPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, IssueStatus $issueStatus): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, IssueStatus $issueStatus): bool
    {
        return false;
    }

    public function delete(User $user, IssueStatus $issueStatus): bool
    {
        return false;
    }
}
