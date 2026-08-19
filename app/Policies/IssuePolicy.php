<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\IssueVisibility;
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
        if (! $this->authorization->can($user, 'view_issues', $this->projectOf($issue))) {
            return false;
        }

        return match ($this->authorization->issueVisibilityFor($user, $this->projectOf($issue))) {
            IssueVisibility::All => true,
            IssueVisibility::Default => ! $issue->is_private || $this->isAuthorOrAssignee($user, $issue),
            IssueVisibility::Own => $this->isAuthorOrAssignee($user, $issue),
        };
    }

    private function isAuthorOrAssignee(?User $user, Issue $issue): bool
    {
        return $user !== null && ($issue->author_id === $user->id || $issue->assigned_to_id === $user->id);
    }

    public function setPrivate(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'set_issues_private', $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'add_issues', $project);
    }

    public function update(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'edit_issues', $this->projectOf($issue));
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'delete_issues', $this->projectOf($issue));
    }

    public function watch(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'add_issue_watchers', $this->projectOf($issue))
            || $this->authorization->can($user, 'view_issues', $this->projectOf($issue));
    }

    /**
     * Adding/removing *other* users as watchers — distinct from watch(),
     * which lets anyone with view access toggle their own watch state.
     */
    public function manageWatchers(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'add_issue_watchers', $this->projectOf($issue));
    }

    public function transitionTo(User $user, Issue $issue, IssueStatus $status): bool
    {
        return $this->workflow->allowedTransitions($issue, $user)->contains('id', $status->id);
    }

    public function manageRelations(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'manage_issue_relations', $this->projectOf($issue));
    }

    public function viewPrivateNotes(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'view_private_notes', $this->projectOf($issue));
    }

    public function setNotesPrivate(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'set_notes_private', $this->projectOf($issue));
    }

    public function move(User $user, Issue $issue): bool
    {
        return $this->authorization->can($user, 'move_issues', $this->projectOf($issue));
    }

    public function copy(User $user, Issue $issue, Project $targetProject): bool
    {
        return $this->authorization->can($user, 'copy_issues', $this->projectOf($issue))
            && $this->authorization->can($user, 'add_issues', $targetProject);
    }

    /**
     * The issue's project, loaded on demand. Policies are reached from every
     * corner of the app — often with an Issue nobody eager-loaded a project
     * onto — so they resolve it explicitly rather than relying on an
     * implicit lazy load, which Model::preventLazyLoading() forbids outside
     * production.
     */
    private function projectOf(Issue $issue): Project
    {
        return $issue->loadMissing('project')->project;
    }
}
