<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class StringFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::String;
    }

    public function label(): string
    {
        return '文字列';
    }

    public function storageColumn(): string
    {
        return 'value_string';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' ? null : (string) $input;
    }

    public function castValue(mixed $stored): mixed
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
}
