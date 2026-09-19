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
     * Creating a top-level project takes the global add_project permission
     * (Redmine's, granted through any of the user's roles); administrators
     * always may, via Gate::before. The creator becomes a member with the
     * default role — see Project::addDefaultMember().
     */
    public function create(User $user): bool
    {
        return $this->authorization->canGlobally($user, 'add_project');
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

    /**
     * Matches Redmine's Project#deletable?: an admin may delete a project
     * (and, per kalnoy/nestedset's NodeTrait::deleteDescendants(), its
     * whole subtree) regardless of children — handled by Gate::before's
     * admin bypass before this method is even reached. A non-admin with
     * delete_project may only delete a leaf project, to avoid a single
     * member action silently taking out subprojects they may not manage.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'delete_project', $project) && $project->isLeaf();
    }

    /**
     * Redmine's select_project_publicity: choosing whether the project is
     * public, split off edit_project.
     */
    public function selectPublicity(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'select_project_publicity', $project);
    }

    /**
     * Redmine's manage_project_activities: turning the time-tracking
     * activities on or off for this project.
     */
    public function manageActivities(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_project_activities', $project);
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
