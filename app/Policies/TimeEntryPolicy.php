<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TimeEntryVisibility;
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

    /**
     * The cross-project time entry list and report (/time_entries): Redmine's
     * find_optional_project runs authorize_global, so they need
     * view_time_entries on at least one project.
     */
    public function viewAnyGlobally(?User $user): bool
    {
        return $this->authorization->canGlobally($user, 'view_time_entries');
    }

    public function view(?User $user, TimeEntry $timeEntry): bool
    {
        if (! $this->authorization->can($user, 'view_time_entries', $timeEntry->project)) {
            return false;
        }

        if ($this->authorization->timeEntryVisibilityFor($user, $timeEntry->project) !== TimeEntryVisibility::Own) {
            return true;
        }

        return $user !== null && $timeEntry->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'log_time', $project);
    }

    public function import(User $user, Project $project): bool
    {
        return $this->create($user, $project) && $this->authorization->can($user, 'import_time_entries', $project);
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        $project = $timeEntry->loadMissing('project')->project;

        // Redmine's TimeEntry#editable_by?: your own entries need
        // edit_own_time_entries, anyone else's need edit_time_entries.
        return ($timeEntry->user_id === $user->id && $this->authorization->can($user, 'edit_own_time_entries', $project))
            || $this->authorization->can($user, 'edit_time_entries', $project);
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->update($user, $timeEntry);
    }
}
