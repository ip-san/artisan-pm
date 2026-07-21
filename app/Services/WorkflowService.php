<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkflowFieldRuleType;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\User;
use App\Models\WorkflowFieldRule;
use App\Models\WorkflowTransition;
use App\Support\Authorization\AuthorizationService;
use Closure;
use Illuminate\Support\Collection;

/**
 * Resolves the workflow matrix: which status transitions and field
 * rules (required / read-only) apply to a given user on a given issue,
 * based on their role(s) in the issue's project plus whether they are
 * its author or assignee. Policies delegate here rather than querying
 * the workflow tables directly.
 */
final class WorkflowService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @return Collection<int, IssueStatus>
     */
    public function allowedTransitions(Issue $issue, User $user): Collection
    {
        if ($user->is_admin) {
            return IssueStatus::query()->orderBy('position')->get();
        }

        $roleIds = $this->roleIdsFor($issue, $user);

        if ($roleIds->isEmpty()) {
            return collect();
        }

        $newStatusIds = WorkflowTransition::query()
            ->where('tracker_id', $issue->tracker_id)
            ->whereIn('role_id', $roleIds)
            ->where('old_status_id', $issue->status_id)
            ->where($this->authorRelationScope($issue, $user))
            ->pluck('new_status_id')
            ->unique();

        return IssueStatus::query()->whereIn('id', $newStatusIds)->orderBy('position')->get();
    }

    /**
     * @return array<string, 'required'|'read_only'>
     */
    public function fieldRules(Issue $issue, User $user): array
    {
        if ($user->is_admin) {
            return [];
        }

        $roleIds = $this->roleIdsFor($issue, $user);

        if ($roleIds->isEmpty()) {
            return [];
        }

        $rules = WorkflowFieldRule::query()
            ->where('tracker_id', $issue->tracker_id)
            ->whereIn('role_id', $roleIds)
            ->where('status_id', $issue->status_id)
            ->where($this->authorRelationScope($issue, $user))
            ->get();

        $resolved = [];

        foreach ($rules as $rule) {
            $existing = $resolved[$rule->field_name] ?? null;

            // required beats read_only when multiple roles disagree.
            if ($existing === WorkflowFieldRuleType::Required->value) {
                continue;
            }

            $resolved[$rule->field_name] = $rule->rule->value;
        }

        return $resolved;
    }

    /**
     * @return Collection<int, int>
     */
    private function roleIdsFor(Issue $issue, User $user): Collection
    {
        return $this->authorization->rolesFor($user, $issue->project)->pluck('id');
    }

    private function authorRelationScope(Issue $issue, User $user): Closure
    {
        $isAuthor = $issue->author_id === $user->id;
        $isAssignee = $issue->assigned_to_id !== null && $issue->assigned_to_id === $user->id;

        return function ($query) use ($isAuthor, $isAssignee) {
            $query->where(function ($group) {
                $group->where('author', false)->where('assignee', false);
            });

            if ($isAuthor) {
                $query->orWhere('author', true);
            }

            if ($isAssignee) {
                $query->orWhere('assignee', true);
            }
        };
    }
}
