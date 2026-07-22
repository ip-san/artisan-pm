<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

/**
 * Strategy for one custom_fields.field_format value: knows which
 * custom_field_values column it's stored in, how to validate and cast
 * values for that shape, and what the admin form needs to render it.
 * Registered into FormatRegistry so both core formats and (eventually)
 * plugin-contributed formats share one lookup path.
 */
interface FormatContract
{
    public function key(): CustomFieldFormat;

    public function label(): string;

    /**
     * Which typed column on custom_field_values this format is stored in.
     *
     * @return 'value_string'|'value_text'|'value_int'|'value_float'|'value_date'|'value_bool'
     */
    public function storageColumn(): string;

    /**
     * Normalize a raw form input value before it's persisted to storageColumn().
     */
    public function prepareValue(mixed $input): mixed;

    /**
     * Cast a stored column value back to its PHP representation for
     * display. $field is the owning custom field — most formats ignore
     * it, but a format whose storage is an id referencing a separate
     * options table (like EnumerationFormat) needs it to know which
     * field's options to resolve against.
     */
    public function castValue(mixed $stored, CustomField $field): mixed;

    /**
     * @return array<int, string|Rule|In|Exists>
     */
    public function validationRules(CustomField $field): array;

    /**
     * Selectable options as stored-value => display-label pairs, for
     * formats whose input is a choice among a fixed set (list,
     * enumeration). Free-input formats return an empty array. Single
     * source of truth for the option list — the form input component and
     * the query-filter engine both read from here.
     *
     * @return array<string, string>
     */
    public function options(CustomField $field): array;
}
