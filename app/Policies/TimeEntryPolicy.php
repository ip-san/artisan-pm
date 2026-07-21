<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class TimeEntryPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_time_entries', $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'log_time', $project);
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $timeEntry->user_id === $user->id
            || $this->authorization->can($user, 'edit_time_entries', $timeEntry->project);
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->update($user, $timeEntry);
    }
}
