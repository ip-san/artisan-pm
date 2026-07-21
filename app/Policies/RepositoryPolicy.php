<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class RepositoryPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_changesets', $project);
    }

    public function view(?User $user, Repository $repository): bool
    {
        return $this->authorization->can($user, 'view_changesets', $repository->project);
    }

    public function manage(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_repository', $project);
    }
}
