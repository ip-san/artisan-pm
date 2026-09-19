<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Member of group" on the user list (Redmine's is_member_of_group): matches
 * users belonging to any of the chosen groups, or, negated, to none of them.
 */
final class UserGroupFilter implements FilterableField
{
    public function key(): string
    {
        return 'group_id';
    }

    public function label(): string
    {
        return '所属グループ';
    }

    public function type(): FilterFieldType
    {
        return FilterFieldType::Select;
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    public function options(): array
    {
        return UserFilterFieldRegistry::groupOptions();
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        $ids = array_values(array_filter(array_map('intval', $values)));

        return match ($operator) {
            FilterOperator::Equals, FilterOperator::In => $ids === [] ? $query : $query->whereHas('groups', fn (Builder $groups) => $groups->whereIn('groups.id', $ids)),
            FilterOperator::NotEquals, FilterOperator::NotIn => $ids === [] ? $query : $query->whereDoesntHave('groups', fn (Builder $groups) => $groups->whereIn('groups.id', $ids)),
            FilterOperator::IsEmpty => $query->whereDoesntHave('groups'),
            FilterOperator::IsNotEmpty => $query->whereHas('groups'),
            default => $query,
        };
    }

    public function applySort(Builder $query, string $direction): Builder
    {
        return $query;
    }

    public function isSortable(): bool
    {
        return false;
    }
}
