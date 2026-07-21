<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One filterable/sortable column in a Query — either a native Eloquent
 * column (NativeColumnFilter) or a custom field's EAV value
 * (CustomFieldFilter). QueryFilterEngine treats both uniformly through
 * this interface, which is the whole point of giving custom fields the
 * same `cf_<id>` key convention as native columns use their column name.
 */
interface FilterableField
{
    public function key(): string;

    public function label(): string;

    public function type(): FilterFieldType;

    /**
     * @return array<int, FilterOperator>
     */
    public function operators(): array;

    /**
     * Value => label options for Select-type fields.
     *
     * @return array<int|string, string>
     */
    public function options(): array;

    /**
     * @param  Builder<*>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    public function apply(Builder $query, FilterOperator $operator, array $values): Builder;

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function applySort(Builder $query, string $direction): Builder;

    public function isSortable(): bool;
}
