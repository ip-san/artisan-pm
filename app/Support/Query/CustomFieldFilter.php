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
            CustomFieldFormat::Int, CustomFieldFormat::Float, CustomFieldFormat::Progressbar => FilterFieldType::Integer,
            CustomFieldFormat::Date => FilterFieldType::Date,
            CustomFieldFormat::Bool => FilterFieldType::Boolean,
            CustomFieldFormat::List, CustomFieldFormat::Enumeration, CustomFieldFormat::User, CustomFieldFormat::Version => FilterFieldType::Select,
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
        $options = $this->field->format()->options($this->field);

        // A `user` field can be filtered by "me", the signed-in user.
        return $this->field->field_format === CustomFieldFormat::User ? ['me' => '<< 自分 >>', ...$options] : $options;
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        $column = $this->field->format()->storageColumn();
        $fieldId = $this->field->id;

        if ($this->field->field_format === CustomFieldFormat::User) {
            $values = array_map(fn ($value) => $value === 'me' ? (string) auth()->id() : $value, $values);
        }

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
     * Orders by the field's value through a correlated subquery scoped to
     * this one custom_field_id, so several sorted custom fields never
     * collide on a shared join and a multi-row result can't duplicate
     * issues. Blank sorts as the smallest value (first ascending, last
     * descending) — Redmine's COALESCE(value, '') puts blanks together the
     * same way.
     */
    public function applySort(Builder $query, string $direction): Builder
    {
        $model = $query->getModel();
        $column = $this->field->format()->storageColumn();
        $descending = $direction === 'desc';

        $value = "(SELECT cfv.{$column} FROM custom_field_values cfv"
            .' WHERE cfv.customized_type = ? AND cfv.customized_id = '.$model->getQualifiedKeyName()
            .' AND cfv.custom_field_id = ? ORDER BY cfv.id DESC LIMIT 1)';
        $order = $descending ? 'DESC' : 'ASC';
        $bindings = [$this->field->customized_type->value, $this->field->id];

        // "NULLS FIRST/LAST" is PostgreSQL-only, and PostgreSQL, MySQL and
        // SQLite disagree on where NULL sorts by default, so blanks are
        // ordered explicitly by a not-null flag ahead of the value.
        return $query->orderByRaw(
            "{$value} IS NOT NULL {$order}, {$value} {$order}",
            [...$bindings, ...$bindings],
        );
    }

    /**
     * A multi-value field has no single value to order by (Redmine leaves
     * it out too).
     */
    public function isSortable(): bool
    {
        return ! $this->field->multiple;
    }
}
