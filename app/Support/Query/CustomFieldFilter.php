<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomFieldFormat;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters Issues (or any HasCustomFields model) by one custom field's EAV
 * value, applied inside a whereHas() scoped to this field's custom_field_id
 * — never a shared join — so multiple custom-field filters ANDed together
 * don't collide on the same joined table.
 */
final class CustomFieldFilter implements FilterableField
{
    public function __construct(
        private readonly CustomField $field,
    ) {}

    public function key(): string
    {
        return "cf_{$this->field->id}";
    }

    public function label(): string
    {
        return $this->field->name;
    }

    public function type(): FilterFieldType
    {
        return match ($this->field->field_format) {
            CustomFieldFormat::Int, CustomFieldFormat::Float => FilterFieldType::Integer,
            CustomFieldFormat::Date => FilterFieldType::Date,
            CustomFieldFormat::Bool => FilterFieldType::Boolean,
            CustomFieldFormat::List, CustomFieldFormat::Enumeration => FilterFieldType::Select,
            CustomFieldFormat::String, CustomFieldFormat::Text, CustomFieldFormat::Link => FilterFieldType::Text,
        };
    }

    public function operators(): array
    {
        return match ($this->type()) {
            FilterFieldType::Text => [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::Equals, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            FilterFieldType::Integer, FilterFieldType::Date => [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            FilterFieldType::Boolean => [FilterOperator::Equals],
            FilterFieldType::Select => [FilterOperator::Equals, FilterOperator::In, FilterOperator::NotIn, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
        };
    }

    public function options(): array
    {
        return $this->field->format()->options($this->field);
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        $column = $this->field->format()->storageColumn();
        $fieldId = $this->field->id;

        return $query->whereHas(
            'customFieldValues',
            fn (Builder $valueQuery) => FilterOperatorApplier::apply(
                $valueQuery->where('custom_field_id', $fieldId),
                $column,
                $operator,
                $values,
            )
        );
    }

    /**
     * Sorting by an EAV value needs a join scoped to this one custom_field_id
     * rather than the plain orderBy() a native column uses — deferred; a
     * custom field simply isn't offered as a sort option for now.
     */
    public function applySort(Builder $query, string $direction): Builder
    {
        return $query;
    }

    public function isSortable(): bool
    {
        return false;
    }
}
