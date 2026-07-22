<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class IntFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Int;
    }

    public function label(): string
    {
        return '整数';
    }

    public function storageColumn(): string
    {
        return 'value_int';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' || $input === null ? null : (int) $input;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        return $stored === null ? null : (int) $stored;
    }

    public function validationRules(CustomField $field): array
    {
        return ['integer'];
    }
}
