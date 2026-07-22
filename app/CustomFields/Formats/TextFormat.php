<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;

final class TextFormat implements FormatContract
{
    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Text;
    }

    public function label(): string
    {
        return 'テキスト';
    }

    public function storageColumn(): string
    {
        return 'value_text';
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
        return ['string'];
    }

    public function options(CustomField $field): array
    {
        return [];
    }
}
