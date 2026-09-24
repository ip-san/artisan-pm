<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\FilterOperator;
use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Support\Query\TextMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * SQL building blocks shared by the filter sets that go beyond plain
 * columns (IssueExtraFilterFields, TimeEntryExtraFilterFields): Redmine's
 * text, tree, group and role conditions and the "which groups may the
 * viewer see" rule. The using class provides $viewer (?User) and
 * $authorization (AuthorizationService).
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
