<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use Illuminate\Validation\Rule;

final class ListFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::List;
    }

    public function label(): string
    {
        return 'リスト選択';
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
        return ['string', Rule::in($field->possible_values ?? [])];
    }

    public function options(CustomField $field): array
    {
        $values = $field->possible_values ?? [];

        return array_combine($values, $values);
    }
}
