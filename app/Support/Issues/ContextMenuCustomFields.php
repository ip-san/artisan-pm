<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * The custom field submenus of a right-click menu (Redmine's
 * context_menus `@options_by_custom_field`): of the fields a bulk edit may
 * set, the single-value ones with a fixed set of values — list, key/value
 * list and boolean — each with its values. Picking `NONE` clears a field
 * that is not required.
 */
final class ContextMenuCustomFields
{
    public const string NONE = '__none__';

    /**
     * @param  Collection<int, CustomField>  $fields  the bulk-editable fields
     * @return Collection<int, array{field: CustomField, options: array<string, string>}>
     */
    public static function optionsFor(Collection $fields): Collection
    {
        return $fields
            ->reject(fn (CustomField $field) => $field->multiple)
            ->map(fn (CustomField $field) => ['field' => $field, 'options' => self::options($field)])
            ->filter(fn (array $entry) => $entry['options'] !== [])
            ->values();
    }

    /**
     * @return array<string, string> value => label
     */
    private static function options(CustomField $field): array
    {
        return match ($field->field_format) {
            CustomFieldFormat::Bool => ['1' => __('はい'), '0' => __('いいえ')],
            CustomFieldFormat::List, CustomFieldFormat::Enumeration => collect($field->format()->options($field))
                ->mapWithKeys(fn (string $label, int|string $value) => [(string) $value => $label])
                ->all(),
            default => [],
        };
    }
}
