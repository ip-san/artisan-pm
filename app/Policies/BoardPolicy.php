<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class BoardPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_messages', $project);
    }

    public function view(?User $user, Board $board): bool
    {
        return $this->authorization->can($user, 'view_messages', $board->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_boards', $project);
    }

    public function update(User $user, Board $board): bool
    {
        return $this->authorization->can($user, 'manage_boards', $board->project);
    }

    public function delete(User $user, Board $board): bool
    {
        return $this->update($user, $board);
    }
}
