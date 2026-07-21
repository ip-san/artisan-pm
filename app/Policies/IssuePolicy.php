<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Authorization\AuthorizationService;

final class IssuePolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WorkflowService $workflow,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_issues', $project);
    }

    public function view(?User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'view_issues', $issue->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'add_issues', $project);
    }

    public function update(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'edit_issues', $issue->project);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'delete_issues', $issue->project);
    }

    public function watch(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'add_issue_watchers', $issue->project)
            || $this->authorization->can($user, 'view_issues', $issue->project);
    }

    public function transitionTo(User $user, Issue $issue, IssueStatus $status): bool
    {
        return $this->workflow->allowedTransitions($issue, $user)->contains('id', $status->id);
    }

    public function manageRelations(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'manage_issue_relations', $issue->project);
    }
}
