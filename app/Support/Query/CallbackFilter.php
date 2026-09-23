<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A filter whose SQL is not a plain column comparison — an EXISTS
 * subquery on journals, watchers or attachments, a recursive walk of the
 * issue tree, and so on (Redmine's `sql_for_<field>_field` methods). The
 * condition is supplied as a closure so each such filter does not need a
 * class of its own. These filters are never sortable: there is no single
 * column to order by.
 */
final class CallbackFilter implements FilterableField
{
    /**
     * @param  array<int, FilterOperator>  $operators
     * @param  Closure(Builder<*>, FilterOperator, array<int, mixed>): Builder<*>  $applier
     * @param  ?Closure(): array<int|string, string>  $optionsResolver
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly FilterFieldType $fieldType,
        private readonly array $operators,
        private readonly Closure $applier,
        private readonly ?Closure $optionsResolver = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): FilterFieldType
    {
        return $this->fieldType;
    }

    public function operators(): array
    {
        return $this->operators;
    }

    public function options(): array
    {
        return $this->optionsResolver ? ($this->optionsResolver)() : [];
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        return ($this->applier)($query, $operator, $values);
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
