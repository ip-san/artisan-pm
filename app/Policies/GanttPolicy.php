<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * Gantt has no backing model of its own — see CalendarPolicy for the same
 * shape and the reasoning (a view over Issue data gated by its own
 * project module/permission).
 */
final class GanttPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function view(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_gantt', $project);
    }
}
