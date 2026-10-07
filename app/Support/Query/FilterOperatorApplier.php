<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterOperator;
use App\Support\Format\DateTimes;
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
        // A filter still waiting for its value (an operator picked, the
        // field left blank) matches everything, like Redmine's — it must not
        // reach the query builder as `column >= NULL`, which throws.
        if ($values === [] && $operator->requiresValue()) {
            return $query;
        }

        if ($operator === FilterOperator::Between && count($values) < 2) {
            return $query;
        }

        return match ($operator) {
            FilterOperator::Equals => $query->where($column, $values[0] ?? null),
            // Redmine's "!" (query.rb sql_for_field): `col IS NULL OR col NOT IN (...)`, so a row with
            // nothing set (no category, no target version) counts as "not A" rather than vanishing.
            FilterOperator::NotEquals => $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '!=', $values[0] ?? null)),
            FilterOperator::In => $query->whereIn($column, $values),
            FilterOperator::NotIn => $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, $values)),
            FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::ContainsAny,
            FilterOperator::StartsWith, FilterOperator::EndsWith => tap($query, fn () => TextMatch::apply($query, $column, $operator, (string) ($values[0] ?? ''))),
            FilterOperator::IsEmpty => $query->whereNull($column),
            FilterOperator::IsNotEmpty => $query->whereNotNull($column),
            FilterOperator::GreaterOrEqual => $query->where($column, '>=', $values[0] ?? null),
            FilterOperator::LessOrEqual => $query->where($column, '<=', $values[0] ?? null),
            FilterOperator::Between => $query->whereBetween($column, [$values[0] ?? null, $values[1] ?? null]),
            FilterOperator::InTheLastDays => $query->where(
                $column, '>=', DateTimes::today()->subDays((int) ($values[0] ?? 0))->toDateString()
            ),
            // The relation operators mean nothing for a plain column; no
            // column field offers them.
            FilterOperator::AnyOpenIssues, FilterOperator::NoOpenIssues,
            FilterOperator::AnyIssuesInProject, FilterOperator::AnyIssuesNotInProject, FilterOperator::NoIssuesInProject => $query,
        };
    }

    /**
     * A date filter on a timestamp column, as Redmine's Query#date_clause:
     * each typed date is the whole day in the viewer's zone, so "<= 9/24"
     * keeps what was stored later that day and "= 9/24" is not only midnight.
     *
     * @param  Builder<*>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    public static function applyToTimes(Builder $query, string $column, FilterOperator $operator, array $values): Builder
    {
        if (($values === [] && $operator->requiresValue()) || ($operator === FilterOperator::Between && count($values) < 2)) {
            return $query;
        }

        $day = fn (int $index): array => DateTimes::dayBounds((string) $values[$index]);

        return match ($operator) {
            FilterOperator::Equals => $query->whereBetween($column, $day(0)),
            FilterOperator::NotEquals => $query->whereNotBetween($column, $day(0)),
            FilterOperator::GreaterOrEqual => $query->where($column, '>=', $day(0)[0]),
            FilterOperator::LessOrEqual => $query->where($column, '<=', $day(0)[1]),
            FilterOperator::Between => $query->whereBetween($column, [$day(0)[0], $day(1)[1]]),
            FilterOperator::InTheLastDays => $query->where(
                $column, '>=', DateTimes::dayBounds(DateTimes::today()->subDays((int) ($values[0] ?? 0))->toDateString())[0]
            ),
            default => self::apply($query, $column, $operator, $values),
        };
    }
}
