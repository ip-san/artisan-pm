<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Neutralizes a value that a spreadsheet would run as a formula. Text that
 * starts with =, +, - or @ (or a tab or carriage return) is prefixed with an
 * apostrophe so Excel and friends show it instead of evaluating it; plain
 * numbers pass through untouched. Every CSV export writes its cells through
 * here, since names, subjects and custom field values are user input.
 */
final class CsvCell
{
    public static function safe(mixed $value): string
    {
        $text = (string) $value;

        if ($text === '' || is_numeric($text)) {
            return $text;
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int, string>
     */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), array_values($row));
    }
}
