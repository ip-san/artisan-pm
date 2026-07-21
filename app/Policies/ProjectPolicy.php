<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class ProjectPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function view(?User $user, Project $project): bool
    {
        if ($project->isArchived()) {
            return false;
        }

        return $project->is_public || $this->authorization->can($user, 'view_project', $project);
    }

    /**
     * Only administrators may create top-level projects for now — there is
     * no project instance yet to scope a "member" permission check against.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'edit_project', $project);
    }

    public function close(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'close_project', $project);
    }

    /**
     * Archiving is administrator-only in Redmine (unlike close/reopen,
     * which project managers can do) — always false here, same as
     * create(), relying on Gate::before's admin bypass.
     */
    public function archive(User $user, Project $project): bool
    {
        return false;
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'delete_project', $project);
    }

    public function selectModules(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'select_project_modules', $project);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_members', $project);
    }

    public function createSubproject(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'add_subprojects', $project);
    }

    /**
     * Project-level files (not tied to a Version) — same manage_files
     * permission VersionPolicy::manageFiles already uses.
     */
    public function manageFiles(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_files', $project);
    }
}
