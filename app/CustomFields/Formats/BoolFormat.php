<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class BoolFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Bool;
    }

    public function label(): string
    {
        return '真偽値';
    }

    public function storageColumn(): string
    {
        return 'value_bool';
    }

    public function prepareValue(mixed $input): mixed
    {
        return (bool) $input;
    }

    public function castValue(mixed $stored): mixed
    {
        return $stored === null ? null : (bool) $stored;
    }

    public function validationRules(CustomField $field): array
    {
        return ['boolean'];
    }
}
