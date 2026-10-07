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
 *
 * $visibleProjectIds (see CustomFieldVisibility) limits a role-restricted
 * field to the rows of the projects where the viewer may see it: on the
 * other rows the value counts as absent, so they never match the filter
 * and sort as blank — Redmine ANDs visibility_by_project_condition into
 * both. Null means no restriction.
 */
final class CustomFieldFilter implements FilterableField
{
    /**
     * @param  array<int, int>|null  $visibleProjectIds
     */
    public function __construct(
        private readonly CustomField $field,
        private readonly ?array $visibleProjectIds = null,
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
            // Never offered (the registries skip it, Redmine: is_filter_supported = false).
            CustomFieldFormat::Attachment => FilterFieldType::Text,
        };
    }

    public function operators(): array
    {
        return match ($this->type()) {
            FilterFieldType::Text => [FilterOperator::Contains, FilterOperator::ContainsAny, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith, FilterOperator::Equals, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            FilterFieldType::Date => FilterOperator::dateChoices(),
            FilterFieldType::Integer, FilterFieldType::IdList => [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            FilterFieldType::Boolean => [FilterOperator::Equals],
            FilterFieldType::Select => [FilterOperator::Equals, FilterOperator::In, FilterOperator::NotIn, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
        };
    }

    public function options(): array
    {
        $options = $this->field->format()->options($this->field);

        // A `user` field can be filtered by "me", the signed-in user.
        return $this->field->field_format === CustomFieldFormat::User ? ['me' => __('<< 自分 >>'), ...$options] : $options;
    }

    public function apply(Builder $query, FilterOperator $operator, array $values): Builder
    {
        $column = $this->field->format()->storageColumn();
        $fieldId = $this->field->id;

        if ($this->field->field_format === CustomFieldFormat::User) {
            $values = array_map(fn ($value) => $value === 'me' ? (string) auth()->id() : $value, $values);
        }

        // Redmine's sql_for_custom_field: each issue is joined (LEFT OUTER) to its values for this
        // field, so an issue with no value row takes part as a NULL value. "is not" is NOT EXISTS
        // over "is", so a multi-value [A, B] is not "not A", and an issue with no value is. The
        // project visibility stays outside every branch, so a field the viewer may not see cannot
        // be probed by elimination with "is not".
        $textual = in_array($column, ['value_string', 'value_text'], true);
        $ofField = fn (Builder $valueQuery) => $valueQuery->where('custom_field_id', $fieldId);
        $blank = fn (Builder $valueQuery) => $valueQuery->where(fn (Builder $b) => $textual ? $b->whereNull($column)->orWhere($column, '') : $b->whereNull($column));

        return $query->where(fn (Builder $scoped) => $this->restrictToVisibleProjects($scoped)->where(fn (Builder $issue) => match ($operator) {
            FilterOperator::NotIn => $issue->whereDoesntHave('customFieldValues', fn (Builder $valueQuery) => FilterOperatorApplier::apply($ofField($valueQuery), $column, FilterOperator::In, $values)),
            FilterOperator::IsEmpty => $issue->whereDoesntHave('customFieldValues', $ofField)
                ->orWhereHas('customFieldValues', fn (Builder $valueQuery) => $blank($ofField($valueQuery))),
            FilterOperator::IsNotEmpty => $issue->whereHas('customFieldValues', fn (Builder $valueQuery) => $textual
                ? $ofField($valueQuery)->whereNotNull($column)->where($column, '<>', '')
                : $ofField($valueQuery)->whereNotNull($column)),
            FilterOperator::NotContains => $issue->whereDoesntHave('customFieldValues', $ofField)
                ->orWhereHas('customFieldValues', fn (Builder $valueQuery) => $ofField($valueQuery)->where(
                    fn (Builder $b) => FilterOperatorApplier::apply($b, $column, FilterOperator::NotContains, $values)->orWhereNull($column),
                )),
            default => $issue->whereHas('customFieldValues', fn (Builder $valueQuery) => FilterOperatorApplier::apply($ofField($valueQuery), $column, $operator, $values)),
        }));
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function restrictToVisibleProjects(Builder $query): Builder
    {
        if ($this->visibleProjectIds === null) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn('project_id'), $this->visibleProjectIds);
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

        $bindings = [$this->field->customized_type->value, $this->field->id];
        $visibility = '';

        if ($this->visibleProjectIds !== null) {
            $visibility = $this->visibleProjectIds === []
                ? ' AND 1 = 0'
                : ' AND '.$model->qualifyColumn('project_id').' IN ('.implode(', ', array_fill(0, count($this->visibleProjectIds), '?')).')';
            $bindings = [...$bindings, ...$this->visibleProjectIds];
        }

        $value = "(SELECT cfv.{$column} FROM custom_field_values cfv"
            .' WHERE cfv.customized_type = ? AND cfv.customized_id = '.$model->getQualifiedKeyName()
            .' AND cfv.custom_field_id = ?'.$visibility.' ORDER BY cfv.id DESC LIMIT 1)';
        $order = $descending ? 'DESC' : 'ASC';

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
