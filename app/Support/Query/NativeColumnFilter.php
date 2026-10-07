<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class NativeColumnFilter implements FilterableField
{
    /**
     * @param  array<int, FilterOperator>  $operators
     * @param  ?Closure(): array<int|string, string>  $optionsResolver
     * @param  bool  $storesTime  a date filter on a timestamp column (created_at…):
     *                            the typed dates are days in the viewer's zone
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $column,
        private readonly FilterFieldType $fieldType,
        private readonly array $operators,
        private readonly ?Closure $optionsResolver = null,
        private readonly bool $sortable = true,
        private readonly bool $storesTime = false,
        private readonly bool $resolvesMe = false,
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
        // Redmine's "<< me >>" value: the signed-in user, which matches no one for a visitor.
        if ($this->resolvesMe) {
            $values = array_map(fn ($value) => $value === 'me' ? (string) (auth()->id() ?? 0) : $value, $values);
        }

        return $this->storesTime
            ? FilterOperatorApplier::applyToTimes($query, $this->column, $operator, $values)
            : FilterOperatorApplier::apply($query, $this->column, $operator, $values);
    }

    public function applySort(Builder $query, string $direction): Builder
    {
        return $query->orderBy($this->column, $direction === 'desc' ? 'desc' : 'asc');
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }
}
