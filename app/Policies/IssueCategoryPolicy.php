<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IssueCategory;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class IssueCategoryPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_categories', $project);
    }

    public function view(?User $user, IssueCategory $category): bool
    {
        return $this->authorization->can($user, 'manage_categories', $category->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_categories', $project);
    }

    public function update(User $user, IssueCategory $category): bool
    {
        return $this->authorization->can($user, 'manage_categories', $category->project);
    }

    public function delete(User $user, IssueCategory $category): bool
    {
        return $this->authorization->can($user, 'manage_categories', $category->project);
    }
}
