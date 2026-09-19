<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * Listing and reading members takes Redmine's view_members (a public, read
 * permission); creating, updating and removing them takes manage_members.
 */
final class MemberPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_members', $project);
    }

    public function view(?User $user, Member $member): bool
    {
        return $this->authorization->can($user, 'view_members', $member->project);
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
