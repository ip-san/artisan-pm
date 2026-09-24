<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomizableType;
use App\Enums\WorkflowFieldRuleType;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowFieldRule;
use App\Models\WorkflowTransition;
use App\Support\Authorization\AuthorizationService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * The status a new issue starts in when none (or one outside
     * initialStatuses()) is chosen: the tracker's default status, else the
     * first status; when the workflow's "new issue" row doesn't list it, the
     * first status that row does allow — Redmine's safe_attributes= falling
     * back to new_statuses_allowed_to.first.
     */
    public function defaultInitialStatusId(Project $project, Tracker $tracker, User $creator): ?int
    {
        $default = $tracker->default_status_id ?? IssueStatus::query()->orderBy('position')->value('id');
        $allowed = $this->initialStatuses($project, $tracker, $creator)->pluck('id');

        return $allowed->contains($default) ? $default : ($allowed->first() ?? $default);
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
     * Redmine's Issue#workflow_rule_by_attribute: the rules of every role
     * the user's workflow follows (roles_for_workflow — an administrator
     * gets all roles) are combined, and a field gets a rule only when every
     * one of those roles has one for it; roles that disagree make it
     * required. When any rule applies, an issue custom field limited to
     * some roles counts as read-only for each of the user's roles that may
     * not see it.
     *
     * @return array<string, 'required'|'read_only'>
     */
    public function fieldRules(Issue $issue, User $user): array
    {
        $roleIds = $this->roleIdsForWorkflow($issue, $user);

        if ($roleIds->isEmpty()) {
            return [];
        }

        $rules = WorkflowFieldRule::query()
            ->where('tracker_id', $issue->tracker_id)
            ->whereIn('role_id', $roleIds)
            ->where('status_id', $issue->status_id)
            ->where($this->authorRelationScope($issue, $user))
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        /** @var array<string, array<int, string>> $rulesByField field => role id => rule */
        $rulesByField = [];

        foreach ($rules as $rule) {
            $existing = $rulesByField[$rule->field_name][$rule->role_id] ?? null;

            // Within one role, a general rule and an author/assignee rule
            // for the same field: required wins.
            if ($existing !== WorkflowFieldRuleType::Required->value) {
                $rulesByField[$rule->field_name][$rule->role_id] = $rule->rule->value;
            }
        }

        foreach ($this->roleLimitedIssueCustomFieldRoleIds() as $fieldId => $visibleRoleIds) {
            foreach ($roleIds->diff($visibleRoleIds) as $roleId) {
                $rulesByField["cf_{$fieldId}"][$roleId] = WorkflowFieldRuleType::ReadOnly->value;
            }
        }

        $resolved = [];

        foreach ($rulesByField as $field => $ruleByRole) {
            if (count($ruleByRole) < $roleIds->count()) {
                continue;
            }

            $distinct = array_values(array_unique($ruleByRole));

            $resolved[$field] = count($distinct) === 1 ? $distinct[0] : WorkflowFieldRuleType::Required->value;
        }

        return $resolved;
    }

    /**
     * Redmine's Issue#roles_for_workflow: the user's roles in the project
     * (every role for an administrator) that may add or edit issues
     * (Role#consider_workflow?).
     *
     * @return Collection<int, int>
     */
    private function roleIdsForWorkflow(Issue $issue, User $user): Collection
    {
        $roles = $user->is_admin
            ? Role::query()->get()
            : $this->authorization->rolesFor($user, $issue->loadMissing('project')->project);

        return $roles
            ->filter(fn (Role $role): bool => $role->hasPermission('add_issues') || $role->hasPermission('edit_issues'))
            ->pluck('id')
            ->values();
    }

    /**
     * Issue custom fields visible only to some roles (Redmine's
     * `visible => false` fields and their roles).
     *
     * @return array<int, Collection<int, int>> custom field id => the role ids that may see it
     */
    private function roleLimitedIssueCustomFieldRoleIds(): array
    {
        return DB::table('custom_field_role')
            ->join('custom_fields', 'custom_fields.id', '=', 'custom_field_role.custom_field_id')
            ->where('custom_fields.customized_type', CustomizableType::Issue->value)
            ->get(['custom_field_role.custom_field_id', 'custom_field_role.role_id'])
            ->groupBy('custom_field_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('role_id')->map(fn (mixed $id): int => (int) $id))
            ->all();
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
        // Redmine's assignee_transitions_allowed: the assignee or a member of
        // the assigned group.
        $isAssignee = $issue->isAssignedTo($user);

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
