<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies one FilterOperator to a query builder for a given column —
 * shared between NativeColumnFilter (applies directly to the Issue/
 * TimeEntry query) and CustomFieldFilter (applies inside a whereHas
 * callback scoped to one custom_field_id), since the operator semantics
 * are identical either way; only the column and the surrounding query
 * differ.
 */
final class FilterOperatorApplier
{
    /**
     * @param  Builder<*>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    public static function apply(Builder $query, string $column, FilterOperator $operator, array $values): Builder
    {
        return match ($operator) {
            FilterOperator::Equals => $query->where($column, $values[0] ?? null),
            FilterOperator::NotEquals => $query->where($column, '!=', $values[0] ?? null),
            FilterOperator::In => $query->whereIn($column, $values),
            FilterOperator::NotIn => $query->whereNotIn($column, $values),
            FilterOperator::Contains => $query->where($column, 'like', '%'.($values[0] ?? '').'%'),
            FilterOperator::NotContains => $query->where($column, 'not like', '%'.($values[0] ?? '').'%'),
            FilterOperator::IsEmpty => $query->whereNull($column),
            FilterOperator::IsNotEmpty => $query->whereNotNull($column),
            FilterOperator::GreaterOrEqual => $query->where($column, '>=', $values[0] ?? null),
            FilterOperator::LessOrEqual => $query->where($column, '<=', $values[0] ?? null),
            FilterOperator::Between => $query->whereBetween($column, [$values[0] ?? null, $values[1] ?? null]),
            FilterOperator::InTheLastDays => $query->where(
                $column, '>=', now()->subDays((int) ($values[0] ?? 0))->startOfDay()
            ),
        };
    }
}
