<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class FloatFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Float;
    }

    public function label(): string
    {
        return '小数';
    }

    public function storageColumn(): string
    {
        return 'value_float';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' || $input === null ? null : (float) $input;
    }

    public function castValue(mixed $stored): mixed
    {
        return $stored === null ? null : (float) $stored;
    }

    public function validationRules(CustomField $field): array
    {
        return ['numeric'];
    }
}
