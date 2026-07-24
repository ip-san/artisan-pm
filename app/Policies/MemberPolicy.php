<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * Redmine gates every MembersController action (index/show/create/update/
 * destroy) behind the single manage_members permission — there is no
 * separate "view members" permission — so this policy does the same: one
 * ability per action, all delegating to the same manage_members check.
 */
final class MemberPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_members', $project);
    }

    public function view(?User $user, Member $member): bool
    {
        return $this->authorization->can($user, 'manage_members', $member->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_members', $project);
    }

    public function update(User $user, Member $member): bool
    {
        return $this->authorization->can($user, 'manage_members', $member->project);
    }

    public function delete(User $user, Member $member): bool
    {
        return $this->authorization->can($user, 'manage_members', $member->project);
    }
}
