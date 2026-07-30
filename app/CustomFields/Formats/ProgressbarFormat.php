<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

/**
 * Matches Redmine's "progressbar" field format (Redmine::FieldFormat::
 * ProgressbarFormat) — an integer clamped to 0-100. Storage/validation are
 * otherwise identical to IntFormat with a fixed range; Redmine's
 * `ratio_interval` field attribute (which steps the admin's edit `<select>`
 * in fixed increments instead of a free-text/number input, defaulting from
 * `Setting.issue_done_ratio_interval`) is intentionally not reproduced —
 * it only affects the granularity offered while picking a value, not the
 * stored value's validity, and this app has no equivalent stepped-select
 * form control anywhere yet. A plain number input (shared with Int/Float
 * in custom-field-input.blade.php) covers the same value space. Likewise,
 * this app doesn't render an actual progress-bar graphic for its native
 * done_ratio field either (issues/show.blade.php shows it as plain "N%"
 * text), so a custom field of this format is displayed the same way
 * rather than introducing a one-off visual component.
 */
final class ProgressbarFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Progressbar;
    }

    public function label(): string
    {
        return '進捗率(0〜100)';
    }

    public function storageColumn(): string
    {
        return 'value_int';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' || $input === null ? null : (int) $input;
    }

    /**
     * Clamps rather than rejects on read, matching Redmine's own
     * cast_single_value (`value.to_i.clamp(0, 100)`) — validationRules()
     * already rejects an out-of-range value at save time, so this only
     * guards display of any value written before that rule existed.
     */
    public function castValue(mixed $stored, CustomField $field): mixed
    {
        return $stored === null ? null : max(0, min(100, (int) $stored));
    }

    public function validationRules(CustomField $field): array
    {
        return ['integer', 'min:0', 'max:100'];
    }

    public function options(CustomField $field): array
    {
        return [];
    }
}
