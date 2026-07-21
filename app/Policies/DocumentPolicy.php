<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class DocumentPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_documents', $project);
    }

    public function view(?User $user, Document $document): bool
    {
        return $this->authorization->can($user, 'view_documents', $document->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'add_documents', $project);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->authorization->can($user, 'edit_documents', $document->project);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->authorization->can($user, 'delete_documents', $document->project);
    }
}
