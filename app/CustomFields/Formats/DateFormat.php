<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class DateFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Date;
    }

    public function label(): string
    {
        return '日付';
    }

    public function storageColumn(): string
    {
        return 'value_date';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' ? null : $input;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        return $stored;
    }

    public function validationRules(CustomField $field): array
    {
        return ['date'];
    }

    public function options(CustomField $field): array
    {
        return [];
    }
}
