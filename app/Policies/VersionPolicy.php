<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Support\Authorization\AuthorizationService;

final class VersionPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_files', $project);
    }

    /**
     * The roadmap (versions#index in Redmine, mapped under view_issues —
     * distinct from viewAny()/view() above, which gate the Files module's
     * per-version file browsing on view_files instead).
     */
    public function viewRoadmap(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_issues', $project);
    }

    public function view(?User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'view_files', $version->project);
    }

    public function manageFiles(User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'manage_files', $version->project);
    }

    /**
     * Gates the version CRUD admin screens (list/create/edit/delete) —
     * distinct from viewAny()/view(), which gate the Files module's
     * per-version file browsing and stay on view_files so ordinary
     * members keep that access without also holding manage_versions.
     */
    public function manageVersions(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_versions', $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_versions', $project);
    }

    public function update(User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'manage_versions', $version->project);
    }

    public function delete(User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'manage_versions', $version->project);
    }
}
