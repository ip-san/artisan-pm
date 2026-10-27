<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Support\Query\CallbackFilter;
use App\Support\Query\CustomFieldFilter;
use App\Support\Query\FilterableField;
use App\Support\Query\TextMatch;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * SQL building blocks shared by the filter sets that go beyond plain
 * columns (IssueExtraFilterFields, TimeEntryExtraFilterFields): Redmine's
 * text, tree, group and role conditions and the "which groups may the
 * viewer see" rule, and the filters both sets offer in the same form.
 * The using class provides $viewer (?User), $authorization
 * (AuthorizationService), $listProject (?Project), $scopeProjects
 * (Closure(): Collection<int, Project>) and $resolvedScopeProjects.
 */
trait BuildsQueryFilterConditions
{
    /** @var ?array<int, string> */
    private ?array $visibleGroupOptions = null;

    /**
     * Redmine's sql_for_assigned_to_role_field / sql_for_author_role_field.
     * The negated operators, as in Redmine, also match an issue with
     * nobody in $userColumn. With $groupColumn (a group assignee), the
     * group's own member row counts, as a group Principal's does in Redmine.
     *
     * @param  Builder<*>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    private function applyRole(Builder $query, string $userColumn, FilterOperator $operator, array $values, ?string $groupColumn = null): Builder
    {
        if ($operator->requiresValue() && $values === []) {
            return $query;
        }

        $roleIds = in_array($operator, [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], true) ? null : self::idList($values);
        $userColumn = $query->qualifyColumn($userColumn);
        $groupColumn = $groupColumn !== null ? $query->qualifyColumn($groupColumn) : null;
        $projectColumn = $query->qualifyColumn('project_id');

        $membership = function (QueryBuilder $members) use ($userColumn, $groupColumn, $projectColumn, $roleIds): void {
            $members->select($members->raw('1'))
                ->from('members')
                ->whereColumn('members.project_id', $projectColumn)
                ->where(fn (QueryBuilder $principal) => $principal->whereColumn('members.user_id', $userColumn)
                    ->orWhereIn('members.group_id', fn (QueryBuilder $groups) => $groups->select('group_user.group_id')->from('group_user')->whereColumn('group_user.user_id', $userColumn))
                    ->when($groupColumn !== null, fn (QueryBuilder $group) => $group->orWhereColumn('members.group_id', $groupColumn)));

            if ($roleIds !== null) {
                $members->whereExists(fn (QueryBuilder $memberRoles) => $memberRoles->select($memberRoles->raw('1'))
                    ->from('member_roles')
                    ->whereColumn('member_roles.member_id', 'members.id')
                    ->whereIn('member_roles.role_id', $roleIds));
            }
        };

        if (! in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn, FilterOperator::IsEmpty], true)) {
            return $query->whereExists($membership);
        }

        return $query->where(fn (Builder $outside) => $outside
            ->where(fn (Builder $nobody) => $nobody->whereNull($userColumn)->when($groupColumn !== null, fn (Builder $q) => $q->whereNull($groupColumn)))
            ->orWhereNotExists($membership));
    }

    /**
     * The users in $column belong to one of $groupIds (null: to any
     * group), or, negated, the column is empty or they belong to none.
     * With $groupColumn (a group assignee), the group itself matches too,
     * like Redmine's `group.user_ids + [group.id]`.
     *
     * @param  Builder<*>  $query
     * @param  ?array<int, int>  $groupIds
     * @return Builder<*>
     */
    private static function whereUserInGroups(Builder $query, string $column, ?array $groupIds, bool $negated, ?string $groupColumn = null): Builder
    {
        $groupUsers = fn (QueryBuilder $members) => $members->select('group_user.user_id')
            ->from('group_user')
            ->when($groupIds !== null, fn (QueryBuilder $chosen) => $chosen->whereIn('group_user.group_id', $groupIds ?? []));

        if ($negated) {
            return $query
                ->where(fn (Builder $outside) => $outside->whereNull($column)->orWhereNotIn($column, $groupUsers))
                ->when($groupColumn !== null, fn (Builder $q) => $q->where(fn (Builder $group) => $groupIds === null
                    ? $group->whereNull($groupColumn)
                    : $group->whereNull($groupColumn)->orWhereNotIn($groupColumn, $groupIds)));
        }

        return $query->where(fn (Builder $inside) => $inside->whereIn($column, $groupUsers)
            ->when($groupColumn !== null, fn (Builder $q) => $groupIds === null
                ? $q->orWhereNotNull($groupColumn)
                : $q->orWhereIn($groupColumn, $groupIds)));
    }

    /**
     * Redmine's group filters (sql_for_member_of_group_field, author.group,
     * user.group): the person in $userColumn belongs to one of the groups
     * (none/any: to no group / to some group). Only groups the viewer can
     * see are offered or honoured. With $groupColumn (a group assignee),
     * the group itself counts as being in the group.
     *
     * @param  array<int, FilterOperator>  $operators
     */
    private function groupMembershipFilter(string $key, string $label, array $operators, string $userColumn, ?string $groupColumn = null): FilterableField
    {
        return new CallbackFilter(
            $key,
            $label,
            FilterFieldType::Select,
            $operators,
            function (Builder $query, FilterOperator $operator, array $values) use ($userColumn, $groupColumn): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $groupIds = in_array($operator, [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], true)
                    ? null
                    : array_values(array_intersect(self::idList($values), array_keys($this->visibleGroupOptions())));

                return self::whereUserInGroups(
                    $query,
                    $query->qualifyColumn($userColumn),
                    $groupIds,
                    in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn, FilterOperator::IsEmpty], true),
                    $groupColumn === null ? null : $query->qualifyColumn($groupColumn),
                );
            },
            fn () => $this->visibleGroupOptions(),
        );
    }

    /**
     * Redmine's project.status (keyed project_status), offered on the
     * cross-project list and on a project that has subprojects.
     */
    private function projectStatusFilter(): ?FilterableField
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
     * A one-hop "association.cf_N" filter (Redmine's project.cf_N,
     * author.cf_N, ...): the record $column points at is among the
     * $candidates whose $field matches. The negated operators become "is
     * not among those that match" (see positiveOf()), whose SQL each
     * filter gives as $negated — whether a row without such a record
     * matches differs between them.
     *
     * @param  Closure(): Builder<*>  $candidates  the records that may match, selecting only their id
     * @param  Closure(Builder<*>, string, Builder<*>): Builder<*>  $negated  ($query, qualified $column, matching ids) => the negated condition
     * @param  bool  $noneNeedsRecord  Redmine keeps "none" an EXISTS over the related record (A17-07): a row with no record has no value that could be blank, so it does not match
     */
    private static function relatedCustomFieldFilter(CustomField $field, string $key, string $label, string $column, Closure $candidates, Closure $negated, bool $noneNeedsRecord = false): FilterableField
    {
        $condition = new CustomFieldFilter($field);

        return new CallbackFilter(
            $key,
            $label,
            $condition->type(),
            $condition->operators(),
            function (Builder $query, FilterOperator $operator, array $values) use ($condition, $column, $candidates, $negated, $noneNeedsRecord): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                [$positive, $isNegated] = $noneNeedsRecord && $operator === FilterOperator::IsEmpty
                    ? [FilterOperator::IsEmpty, false]
                    : self::positiveOf($operator);
                $matching = $condition->apply($candidates(), $positive, $values);
                $qualified = $query->qualifyColumn($column);

                return $isNegated ? $negated($query, $qualified, $matching) : $query->whereIn($qualified, $matching);
            },
            fn () => $condition->options(),
        );
    }

    /**
     * @return Collection<int, Project>
     */
    private function scopeProjects(): Collection
    {
        return $this->resolvedScopeProjects ??= ($this->scopeProjects)();
    }

    /**
     * User ids from filter values, "me" standing for the viewer.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function userIds(array $values): array
    {
        return array_values(array_unique(array_map(
            fn ($value) => $value === 'me' ? (int) $this->viewer?->id : (int) $value,
            $values,
        )));
    }

    /**
     * The groups the viewer may see (Redmine's Group.visible): every group
     * for someone who sees users site-wide, otherwise the groups that are
     * members of a project the viewer can see.
     *
     * @return array<int, string>
     */
    private function visibleGroupOptions(): array
    {
        if ($this->visibleGroupOptions !== null) {
            return $this->visibleGroupOptions;
        }

        $groups = Group::query()->orderBy('name');

        if (! $this->authorization->hasSiteWideUserVisibility($this->viewer)) {
            $groups->whereIn('id', Member::query()->select('group_id')->whereNotNull('group_id')->whereIn('project_id', $this->authorization->visibleProjectIds($this->viewer)));
        }

        return $this->visibleGroupOptions = $groups->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private static function roleOptions(): array
    {
        return Role::query()->givable()->pluck('name', 'id')->all();
    }

    private static function isNegative(FilterOperator $operator): bool
    {
        return in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true);
    }

    /**
     * The positive form of a negated operator, as Redmine's
     * sql_for_custom_field puts NOT in front of the associated subquery —
     * used by the one-hop "association.cf_N" filters (TimeEntryExtraFilterFields,
     * IssueExtraFilterFields) to turn "the related object's field is not X"
     * into "the related object is not among those where the field is X",
     * which, unlike CustomFieldFilter's own negation, also counts a related
     * object with no value at all as matching.
     *
     * @return array{0: FilterOperator, 1: bool}
     */
    private static function positiveOf(FilterOperator $operator): array
    {
        return match ($operator) {
            FilterOperator::NotEquals => [FilterOperator::Equals, true],
            FilterOperator::NotIn => [FilterOperator::In, true],
            FilterOperator::IsEmpty => [FilterOperator::IsNotEmpty, true],
            default => [$operator, false],
        };
    }

    /**
     * Text matching as Redmine's :text filters: "none" and "any" treat an
     * empty string like a missing value.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  array<int, mixed>  $values
     */
    private static function applyText(Builder|QueryBuilder $query, string $column, FilterOperator $operator, array $values): void
    {
        match ($operator) {
            FilterOperator::IsEmpty => $query->where(fn ($blank) => $blank->whereNull($column)->orWhere($column, '')),
            FilterOperator::IsNotEmpty => $query->whereNotNull($column)->where($column, '<>', ''),
            default => $values === [] ? $query : TextMatch::apply($query, $column, $operator, (string) $values[0]),
        };
    }

    /**
     * Issues below any of $ancestorIds in the tree, however deep.
     *
     * @param  Builder<*>  $query
     * @param  array<int, int>  $ancestorIds
     * @return Builder<*>
     */
    private static function whereDescendantOf(Builder $query, array $ancestorIds): Builder
    {
        $table = $query->getModel()->getTable();
        $placeholders = implode(', ', array_fill(0, count($ancestorIds), '?'));

        return $query->whereRaw(
            "{$query->getModel()->getQualifiedKeyName()} IN (WITH RECURSIVE tree_descendants AS ("
            ."SELECT id FROM {$table} WHERE parent_id IN ({$placeholders})"
            ." UNION SELECT child.id FROM {$table} child INNER JOIN tree_descendants ON child.parent_id = tree_descendants.id"
            .') SELECT id FROM tree_descendants)',
            $ancestorIds,
        );
    }

    /**
     * The ids in a value like Redmine's "1, 3 5": every run of digits.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private static function idList(array $values): array
    {
        preg_match_all('/\d+/', implode(',', array_map(fn ($value) => (string) $value, $values)), $matches);

        return array_values(array_unique(array_map('intval', $matches[0])));
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function textOperators(): array
    {
        return [FilterOperator::Contains, FilterOperator::ContainsAny, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    /**
     * Redmine's :list operators on people (= and !), plus their
     * multi-value forms.
     *
     * @return array<int, FilterOperator>
     */
    private static function userOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
    }

    /**
     * Redmine's :tree operators (=, ~, !*, *).
     *
     * @return array<int, FilterOperator>
     */
    private static function treeOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::Contains, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }
}
