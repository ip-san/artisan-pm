<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\CustomFields\Formats\ProjectScopedFormat;
use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The custom field submenus of a right-click menu (Redmine's
 * context_menus `@options_by_custom_field`): of the fields a bulk edit may
 * set, the single-value ones with a fixed set of values — list, key/value
 * list, boolean, and user and version fields (whose values are the ones
 * every selected record's project offers, Redmine's
 * `possible_values_options(@projects)`) — each with its values. Picking
 * `NONE` clears a field that is not required.
 */
final class ContextMenuCustomFields
{
    public const string NONE = '__none__';

    /**
     * @param  Collection<int, CustomField>  $fields  the bulk-editable fields
     * @param  Collection<int, Project>  $projects  the selected records' projects
     * @return Collection<int, array{field: CustomField, options: array<string, string>}>
     */
    public static function optionsFor(Collection $fields, Collection $projects): Collection
    {
        return $fields
            ->reject(fn (CustomField $field) => $field->multiple)
            ->map(fn (CustomField $field) => ['field' => $field, 'options' => self::options($field, $projects)])
            ->filter(fn (array $entry) => $entry['options'] !== [])
            ->values();
    }

    /**
     * @return array<string, string> value => label
     */
    private static function options(CustomField $field, Collection $projects): array
    {
        if ($field->format() instanceof ProjectScopedFormat) {
            return self::commonScopedOptions($field, $projects);
        }

        return match ($field->field_format) {
            CustomFieldFormat::Bool => ['1' => __('はい'), '0' => __('いいえ')],
            CustomFieldFormat::List, CustomFieldFormat::Enumeration => collect($field->format()->options($field))
                ->mapWithKeys(fn (string $label, int|string $value) => [(string) $value => $label])
                ->all(),
            default => [],
        };
    }

    /**
     * The values of a user or version field that every one of $projects
     * offers, in the first project's order (Redmine's
     * `possible_values_options(projects)`, the intersection).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, string> value => label
     */
    public static function commonScopedOptions(CustomField $field, Collection $projects): array
    {
        $format = $field->format();

        if (! $format instanceof ProjectScopedFormat || $projects->isEmpty()) {
            return [];
        }

        $common = null;

        foreach ($projects as $project) {
            $options = collect($format->optionsFor($field, $project))->mapWithKeys(fn (string $label, int|string $value) => [(string) $value => $label])->all();
            $common = $common === null ? $options : array_intersect_key($common, $options);
        }

        return $common ?? [];
    }
}
