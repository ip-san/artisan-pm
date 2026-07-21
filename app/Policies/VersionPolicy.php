<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Support\Authorization\AuthorizationService;

/**
 * Covers only the "Files" module's use of Version (viewing/uploading the
 * release files attached to a version) — full version CRUD (create/edit/
 * delete a Version itself, gated by manage_versions) has no UI yet and is
 * out of this policy's scope.
 */
final class VersionPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_files', $project);
    }

    public function view(?User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'view_files', $version->project);
    }

    public function manageFiles(User $user, Version $version): bool
    {
        return $this->authorization->can($user, 'manage_files', $version->project);
    }
}
