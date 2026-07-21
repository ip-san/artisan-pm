<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Applies a set of stored filter/sort/group definitions to an Eloquent
 * query, resolving each definition's field key against a registry of
 * FilterableField instances (native columns and, for Issues, custom
 * fields). The same engine instance is meant to be reused across the
 * issue list, CSV export, and (future) Gantt/calendar views — they only
 * differ in how they render the resulting rows, not in how filtering
 * works.
 */
final class QueryFilterEngine
{
    /**
     * @param  Collection<string, FilterableField>  $fields  keyed by field key
     */
    public function __construct(
        private readonly Collection $fields,
    ) {}

    public function field(string $key): ?FilterableField
    {
        return $this->fields->get($key);
    }

    /**
     * @return Collection<string, FilterableField>
     */
    public function fields(): Collection
    {
        return $this->fields;
    }

    /**
     * $filters ultimately comes from JSON (a stored Query row or an ad-hoc
     * request), so 'operator' is only optional/untrusted at the type level
     * even though a well-formed filter always includes it.
     *
     * @param  Builder<*>  $query
     * @param  array<string, array{operator?: string, values?: array<int, mixed>}>  $filters
     * @return Builder<*>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $filter) {
            $field = $this->fields->get($key);

            if ($field === null || ! isset($filter['operator'])) {
                continue;
            }

            $operator = FilterOperator::tryFrom($filter['operator']);

            if ($operator === null || ! in_array($operator, $field->operators(), true)) {
                continue;
            }

            $query = $field->apply($query, $operator, $filter['values'] ?? []);
        }

        return $query;
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<int, array{0: string, 1: string}>  $sortCriteria  [key, direction] pairs
     * @return Builder<*>
     */
    public function applySort(Builder $query, array $sortCriteria): Builder
    {
        foreach ($sortCriteria as [$key, $direction]) {
            $field = $this->fields->get($key);

            if ($field?->isSortable()) {
                $query = $field->applySort($query, $direction);
            }
        }

        return $query;
    }
}
