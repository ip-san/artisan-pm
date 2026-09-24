<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Issues\AssigneeChoice;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Redmine's assigned_to_id filter and column over a Principal: the
 * assignee is a user (issues.assigned_to_id) or a group
 * (issues.assigned_to_group_id), groups keyed `group:<id>` (AssigneeChoice).
 * `me` stands for the viewer and the viewer's groups, as in Redmine's
 * Query#statement; the negated operators also match unassigned issues, like
 * Redmine's `IS NULL OR NOT IN`. Sorting orders by the assignee's name.
 */
final class AssigneeFilter implements FilterableField
{
    /**
     * @param  array<int, FilterOperator>  $operators
     * @param  Closure(): array<int|string, string>  $optionsResolver
     */
    public function __construct(
        private readonly string $label,
        private readonly array $operators,
        private readonly Closure $optionsResolver,
        private readonly ?User $viewer,
    ) {}

    public function key(): string
    {
        return 'assigned_to_id';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): FilterFieldType
    {
        return FilterFieldType::Select;
    }

    public function operators(): array
    {
        return $this->operators;
    }

    public function options(): array
    {
        return ($this->optionsResolver)();
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        $userColumn = $query->qualifyColumn('assigned_to_id');
        $groupColumn = $query->qualifyColumn('assigned_to_group_id');

        if ($operator === FilterOperator::IsEmpty) {
            return $query->whereNull($userColumn)->whereNull($groupColumn);
        }

        if ($operator === FilterOperator::IsNotEmpty) {
            return $query->where(fn (Builder $assigned) => $assigned->whereNotNull($userColumn)->orWhereNotNull($groupColumn));
        }

        if (! in_array($operator, [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn], true)) {
            return $query;
        }

        if (in_array($operator, [FilterOperator::Equals, FilterOperator::NotEquals], true)) {
            $values = array_slice($values, 0, 1);
        }

        if ($values === []) {
            return $query;
        }

        [$userIds, $groupIds] = $this->principalIds($values);

        if (in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true)) {
            return $query
                ->when($userIds !== [], fn (Builder $q) => $q->where(fn (Builder $user) => $user->whereNull($userColumn)->orWhereNotIn($userColumn, $userIds)))
                ->when($groupIds !== [], fn (Builder $q) => $q->where(fn (Builder $group) => $group->whereNull($groupColumn)->orWhereNotIn($groupColumn, $groupIds)));
        }

        return $query->where(fn (Builder $assigned) => $assigned
            ->whereIn($userColumn, $userIds)
            ->orWhereIn($groupColumn, $groupIds));
    }

    public function applySort(Builder $query, string $direction): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $userColumn = $query->qualifyColumn('assigned_to_id');
        $groupColumn = $query->qualifyColumn('assigned_to_group_id');

        // `groups` is a reserved word on MySQL 8 / MariaDB, so the names
        // are quoted by the connection's grammar.
        $grammar = $query->getQuery()->getGrammar();
        $users = $grammar->wrapTable('users');
        $groups = $grammar->wrapTable('groups');

        return $query->orderByRaw(
            "COALESCE((SELECT {$users}.{$grammar->wrap('name')} FROM {$users} WHERE {$users}.{$grammar->wrap('id')} = {$userColumn}),"
            ." (SELECT {$groups}.{$grammar->wrap('name')} FROM {$groups} WHERE {$groups}.{$grammar->wrap('id')} = {$groupColumn})) {$direction}"
        );
    }

    public function isSortable(): bool
    {
        return true;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{0: list<int>, 1: list<int>}
     */
    private function principalIds(array $values): array
    {
        $userIds = [];
        $groupIds = [];

        foreach ($values as $value) {
            if ($value === 'me') {
                if ($this->viewer !== null) {
                    $userIds[] = $this->viewer->id;
                    $groupIds = [...$groupIds, ...app(AuthorizationService::class)->groupIdsFor($this->viewer)->all()];
                }

                continue;
            }

            $choice = AssigneeChoice::decode((string) $value);

            if ($choice['assigned_to_group_id'] !== null) {
                $groupIds[] = $choice['assigned_to_group_id'];
            } elseif ($choice['assigned_to_id'] !== null) {
                $userIds[] = $choice['assigned_to_id'];
            }
        }

        return [array_values(array_unique($userIds)), array_values(array_unique($groupIds))];
    }
}
