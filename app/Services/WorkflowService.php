<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkflowFieldRuleType;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
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
            return $this->excludeUnreopenableStatuses(
                $this->excludeUnclosableStatuses(IssueStatus::query()->orderBy('position')->get(), $issue),
                $issue,
            );
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

        $statuses = IssueStatus::query()->whereIn('id', $newStatusIds)->orderBy('position')->get();

        return $this->excludeUnreopenableStatuses($this->excludeUnclosableStatuses($statuses, $issue), $issue);
    }

    /**
     * The statuses a brand-new issue may start in: the targets of the
     * workflow's "new issue" row (transitions with no old status) for the
     * creator's roles, counting the rows that apply to the author since the
     * creator is the author. With no such row the tracker's default status
     * is the only choice — Redmine's Issue#new_statuses_allowed_to for a new
     * record. Administrators may pick any status.
     *
     * @return Collection<int, IssueStatus>
     */
    public function initialStatuses(Project $project, Tracker $tracker, User $creator): Collection
    {
        $default = IssueStatus::query()->whereKey($tracker->default_status_id)->first()
            ?? IssueStatus::query()->orderBy('position')->first();

        if ($creator->is_admin) {
            return IssueStatus::query()->orderBy('position')->get();
        }

        $roleIds = $this->authorization->rolesFor($creator, $project)->pluck('id');

        $statusIds = $roleIds->isEmpty() ? collect() : WorkflowTransition::query()
            ->where('tracker_id', $tracker->id)
            ->whereIn('role_id', $roleIds)
            ->whereNull('old_status_id')
            ->where('assignee', false)
            ->pluck('new_status_id')
            ->unique();

        $statuses = IssueStatus::query()->whereIn('id', $statusIds)->orderBy('position')->get();

        return $statuses->isEmpty() ? collect($default === null ? [] : [$default]) : $statuses;
    }

    /**
     * Drops closed statuses other than the issue's current one when it
     * can't actually be closed (blocked by an open issue, or has open
     * subtasks) — matches Redmine's Issue#closable? gate on
     * new_statuses_allowed_to. The current status stays selectable
     * either way, so "leave it as-is" is never removed as an option.
     *
     * @param  Collection<int, IssueStatus>  $statuses
     * @return Collection<int, IssueStatus>
     */
    private function excludeUnclosableStatuses(Collection $statuses, Issue $issue): Collection
    {
        if ($issue->isClosable()) {
            return $statuses;
        }

        return $statuses
            ->reject(fn (IssueStatus $status) => $status->is_closed && $status->id !== $issue->status_id)
            ->values();
    }

    /**
     * Drops open statuses other than the issue's current one when it
     * can't be reopened (an ancestor is currently closed) — matches
     * Redmine's Issue#reopenable? gate on new_statuses_allowed_to. The
     * current status stays selectable either way, same protection
     * excludeUnclosableStatuses() gives the closed side.
     *
     * @param  Collection<int, IssueStatus>  $statuses
     * @return Collection<int, IssueStatus>
     */
    private function excludeUnreopenableStatuses(Collection $statuses, Issue $issue): Collection
    {
        if ($issue->isReopenable()) {
            return $statuses;
        }

        return $statuses
            ->reject(fn (IssueStatus $status) => ! $status->is_closed && $status->id !== $issue->status_id)
            ->values();
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
        return $this->authorization->rolesFor($user, $issue->loadMissing('project')->project)->pluck('id');
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
