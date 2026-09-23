<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Concerns\BuildsQueryFilterConditions;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The time entry filters beyond the entry's own columns, after Redmine
 * 7.0's TimeEntryQuery#initialize_available_filters: the issue's
 * attributes (Redmine's issue.* filters, keyed issue_* here since a dot
 * would be read as a nested path by the lists' Livewire/URL state), the
 * user's group and role (user.group / user.role), the author, the
 * project's status and the comments.
 *
 * Like Redmine's left_join_issue, which joins only the issues the viewer
 * may see, an issue_* filter only ever looks at visible issues: an entry
 * logged on an issue the viewer cannot see never matches a positive
 * condition on that issue, and counts as "no issue" for the negated ones.
 * Redmine's own issue.fixed_version_id and issue.parent_id conditions skip
 * that check; here they apply it too.
 */
final class TimeEntryExtraFilterFields
{
    use BuildsQueryFilterConditions;

    /** @var ?Collection<int, Project> */
    private ?Collection $resolvedScopeProjects = null;

    /**
     * @param  Closure(): Collection<int, Project>  $scopeProjects  the projects whose entries the list covers, resolved only when a filter needs them
     * @param  ?Project  $listProject  the project whose list it is, null for the cross-project list
     */
    public function __construct(
        private readonly Closure $scopeProjects,
        private readonly ?User $viewer,
        private readonly AuthorizationService $authorization,
        private readonly ?Project $listProject = null,
    ) {}

    /**
     * @return array<int, FilterableField>
     */
    public function fields(): array
    {
        $listOperators = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
        $optionalListOperators = [...$listOperators, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];

        return array_values(array_filter([
            $this->issueId(),
            $this->issueAttribute('issue_tracker_id', __('課題のトラッカー'), FilterFieldType::Select, $listOperators,
                fn (Builder $issues, FilterOperator $operator, array $values) => FilterOperatorApplier::apply($issues, 'issues.tracker_id', $operator, $values),
                fn () => $this->scopeProjects()->flatMap(fn (Project $project) => $project->trackers)->unique('id')->sortBy('position')->pluck('name', 'id')->all()),
            $this->issueParentId(),
            $this->issueAttribute('issue_status_id', __('課題のステータス'), FilterFieldType::Select, $listOperators,
                fn (Builder $issues, FilterOperator $operator, array $values) => FilterOperatorApplier::apply($issues, 'issues.status_id', $operator, $values),
                fn () => IssueStatus::query()->orderBy('position')->pluck('name', 'id')->all()),
            $this->issueAttribute('issue_fixed_version_id', __('課題の対象バージョン'), FilterFieldType::Select, $listOperators,
                fn (Builder $issues, FilterOperator $operator, array $values) => FilterOperatorApplier::apply($issues, 'issues.fixed_version_id', $operator, $values),
                fn () => $this->scopeProjects()->flatMap(fn (Project $project) => $project->versions)->unique('id')->pluck('name', 'id')->all()),
            $this->listProject === null ? null : $this->issueAttribute('issue_category_id', __('課題のカテゴリ'), FilterFieldType::Select, $optionalListOperators,
                fn (Builder $issues, FilterOperator $operator, array $values) => FilterOperatorApplier::apply($issues, 'issues.category_id', $operator, $values),
                fn () => $this->listProject->issueCategories->pluck('name', 'id')->all()),
            $this->issueAttribute('issue_subject', __('課題の題名'), FilterFieldType::Text, [FilterOperator::Contains, FilterOperator::NotContains],
                function (Builder $issues, FilterOperator $operator, array $values): void {
                    self::applyText($issues, 'issues.subject', $operator, $values);
                }),
            $this->userGroup($optionalListOperators),
            $this->userRole($optionalListOperators),
            $this->projectStatus(),
            new CallbackFilter(
                'comments',
                __('コメント'),
                FilterFieldType::Text,
                self::textOperators(),
                function (Builder $query, FilterOperator $operator, array $values): Builder {
                    self::applyText($query, $query->qualifyColumn('comments'), $operator, $values);

                    return $query;
                },
            ),
        ]));
    }

    /**
     * Redmine's sql_for_issue_id_field (a :tree filter): "is" the given
     * issues, "contains" them and every issue below them, none/any: no
     * issue / some issue.
     */
    private function issueId(): FilterableField
    {
        return new CallbackFilter(
            'issue_id',
            __('課題'),
            FilterFieldType::IdList,
            self::treeOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                $column = $query->qualifyColumn('issue_id');

                if ($operator === FilterOperator::IsEmpty) {
                    return $query->whereNull($column);
                }

                if ($operator === FilterOperator::IsNotEmpty) {
                    return $query->whereNotNull($column);
                }

                if ($values === []) {
                    return $query;
                }

                $ids = self::idList($values);

                if ($ids === []) {
                    return $query->whereRaw('1 = 0');
                }

                if ($operator === FilterOperator::Contains) {
                    return $query->whereIn($column, $this->visibleIssues()->select('issues.id')
                        ->where(function (Builder $tree) use ($ids): void {
                            $tree->whereIn('issues.id', $ids)->orWhere(function (Builder $below) use ($ids): void {
                                self::whereDescendantOf($below, $ids);
                            });
                        }));
                }

                return $query->whereIn($column, $ids);
            },
        );
    }

    /**
     * Redmine's sql_for_issue_parent_id_field: "is" the children of the
     * given issues, "contains" everything below them; none/any: the issue
     * has no parent (or there is no visible issue) / has one.
     */
    private function issueParentId(): FilterableField
    {
        return $this->issueAttribute('issue_parent_id', __('課題の親課題'), FilterFieldType::IdList, self::treeOperators(),
            function (Builder $issues, FilterOperator $operator, array $values): void {
                if ($operator === FilterOperator::IsNotEmpty) {
                    $issues->whereNotNull('issues.parent_id');

                    return;
                }

                $ids = self::idList($values);

                if ($ids === []) {
                    $issues->whereRaw('1 = 0');
                } elseif ($operator === FilterOperator::Contains) {
                    self::whereDescendantOf($issues, $ids);
                } else {
                    $issues->whereIn('issues.parent_id', $ids);
                }
            });
    }

    /**
     * Redmine's user.group (keyed user_group): the entry's user belongs to
     * one of the groups (none/any: to no group / to some group). Only groups
     * the viewer can see are offered or honoured.
     *
     * @param  array<int, FilterOperator>  $operators
     */
    private function userGroup(array $operators): FilterableField
    {
        return new CallbackFilter(
            'user_group',
            __('ユーザーのグループ'),
            FilterFieldType::Select,
            $operators,
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $groupIds = in_array($operator, [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], true)
                    ? null
                    : array_values(array_intersect(self::idList($values), array_keys($this->visibleGroupOptions())));

                return self::whereUserInGroups($query, $query->qualifyColumn('user_id'), $groupIds, in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn, FilterOperator::IsEmpty], true));
            },
            fn () => $this->visibleGroupOptions(),
        );
    }

    /**
     * Redmine's user.role (keyed user_role): the entry's user holds one of
     * the roles in the entry's project (none/any: is not / is a member of
     * it at all).
     *
     * @param  array<int, FilterOperator>  $operators
     */
    private function userRole(array $operators): FilterableField
    {
        return new CallbackFilter(
            'user_role',
            __('ユーザーのロール'),
            FilterFieldType::Select,
            $operators,
            fn (Builder $query, FilterOperator $operator, array $values) => $this->applyRole($query, 'user_id', $operator, $values),
            fn () => self::roleOptions(),
        );
    }

    /**
     * Redmine's project.status (keyed project_status), offered on the
     * cross-project list and on a project that has subprojects.
     */
    private function projectStatus(): ?FilterableField
    {
        if ($this->listProject !== null && $this->listProject->_rgt - $this->listProject->_lft <= 1) {
            return null;
        }

        return new CallbackFilter(
            'project_status',
            __('プロジェクトのステータス'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $statuses = fn (Builder $projects) => $projects->whereIn('projects.status', array_map('strval', $values));

                return self::isNegative($operator)
                    ? $query->whereDoesntHave('project', $statuses)
                    : $query->whereHas('project', $statuses);
            },
            fn () => [
                ProjectStatus::Active->value => __('アクティブ'),
                ProjectStatus::Closed->value => __('クローズ'),
            ],
        );
    }

    /**
     * A filter on an attribute of the entry's issue. $condition narrows
     * the visible issues with the positive form of the operator; "is not",
     * "none of" and "none" are its negation, which, as with Redmine's LEFT
     * JOIN, also matches an entry without a (visible) issue.
     *
     * @param  array<int, FilterOperator>  $operators
     * @param  Closure(Builder<Issue>, FilterOperator, array<int, mixed>): mixed  $condition
     * @param  ?Closure(): array<int|string, string>  $options
     */
    private function issueAttribute(string $key, string $label, FilterFieldType $type, array $operators, Closure $condition, ?Closure $options = null): FilterableField
    {
        return new CallbackFilter(
            $key,
            $label,
            $type,
            $operators,
            function (Builder $query, FilterOperator $operator, array $values) use ($condition): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $positive = match ($operator) {
                    FilterOperator::NotEquals => FilterOperator::Equals,
                    FilterOperator::NotIn => FilterOperator::In,
                    FilterOperator::IsEmpty => FilterOperator::IsNotEmpty,
                    default => $operator,
                };

                $issues = $this->visibleIssues()->select('issues.id');
                $issues->where(function (Builder $matching) use ($condition, $positive, $values): void {
                    $condition($matching, $positive, $values);
                });
                $column = $query->qualifyColumn('issue_id');

                return $positive === $operator
                    ? $query->whereIn($column, $issues)
                    : $query->where(fn (Builder $other) => $other->whereNull($column)->orWhereNotIn($column, $issues));
            },
            $options,
        );
    }

    /**
     * The issues the viewer may see in the projects the list covers (a
     * project where the viewer lacks view_issues contributes none).
     *
     * @return Builder<Issue>
     */
    private function visibleIssues(): Builder
    {
        $issueProjects = $this->scopeProjects()
            ->filter(fn (Project $project) => $this->authorization->can($this->viewer, 'view_issues', $project))
            ->values();

        return Issue::query()->visibleToAcrossProjects($this->viewer, $issueProjects);
    }

    /**
     * @return Collection<int, Project>
     */
    private function scopeProjects(): Collection
    {
        return $this->resolvedScopeProjects ??= ($this->scopeProjects)();
    }
}
