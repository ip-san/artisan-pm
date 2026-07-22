<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use Illuminate\Validation\Rule;

/**
 * Matches Redmine's "enumeration" field format (Redmine::FieldFormat::
 * EnumerationFormat) — distinct from the plain "list" format, whose
 * options are just strings in a serialized array. Here each option is a
 * CustomFieldEnumeration row with its own id, so options can be
 * reordered, deactivated without losing history, and safely reassigned
 * when deleted (see CustomFieldEnumeration and the custom field admin
 * form). The stored value is the option's id, not its name.
 */
final class EnumerationFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Enumeration;
    }

    public function label(): string
    {
        return '選択肢(管理された一覧)';
    }

    public function storageColumn(): string
    {
        return 'value_string';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' || $input === null ? null : (string) $input;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return $field->enumerationOptions()->find((int) $stored)?->name;
    }

    public function validationRules(CustomField $field): array
    {
        return [
            'integer',
            Rule::exists('custom_field_enumerations', 'id')
                ->where('custom_field_id', $field->id)
                ->where('active', true),
        ];
    }
}
