<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * Calendar has no backing model of its own — it's a view over Issue data
 * gated by its own project module/permission (view_calendar), matching
 * Redmine treating it as an independent module from issue tracking.
 */
final class CalendarPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function view(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_calendar', $project);
    }
}
