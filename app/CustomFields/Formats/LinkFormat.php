<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

/**
 * Matches Redmine's LinkFormat: a URL stored and validated exactly like a
 * plain string (same min/max length + regexp knobs as StringFormat, and
 * — matching Redmine — no extra "is this a valid URL" check at save
 * time), but rendered as a clickable link wherever custom field values
 * are displayed rather than plain text, auto-prefixing "http://" when
 * the stored value has no scheme. Redmine's own url_pattern
 * field-template feature (%value%/%id% substitution) isn't ported — the
 * stored value is always the URL itself.
 */
final class LinkFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Link;
    }

    public function label(): string
    {
        return 'リンク';
    }

    public function storageColumn(): string
    {
        return 'value_string';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' ? null : (string) $input;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        return $stored;
    }

    public function validationRules(CustomField $field): array
    {
        $rules = ['string'];

        if ($field->max_length !== null) {
            $rules[] = "max:{$field->max_length}";
        }

        if ($field->min_length !== null) {
            $rules[] = "min:{$field->min_length}";
        }

        if ($field->regexp !== null) {
            $rules[] = "regex:{$field->regexp}";
        }

        return $rules;
    }

    public function options(CustomField $field): array
    {
        return [];
    }

    /**
     * The href to link to for a stored value — Redmine auto-prepends
     * "http://" when the value has no scheme of its own, rather than
     * rejecting it at validation time.
     */
    public static function href(string $value): string
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 ? $value : "http://{$value}";
    }
}
