<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Matches Redmine's CustomField#default_value_mode — only meaningful for
 * a `date` format field. `FixedDate` treats default_value as a literal
 * date string; `DateOffset` treats it as an integer number of days
 * relative to "today" at read time (Redmine's `User.current.today +
 * Integer(self[:default_value])`), resolved in CustomField::defaultValue().
 */
enum CustomFieldDefaultValueMode: string
{
    case FixedDate = 'fixed_date';
    case DateOffset = 'date_offset';

    public function label(): string
    {
        return match ($this) {
            self::FixedDate => '絶対日付',
            self::DateOffset => '相対日付(今日からの日数)',
        };
    }
}
