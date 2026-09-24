<?php

declare(strict_types=1);

namespace App\Support\Format;

use App\Models\Setting;
use Illuminate\Support\Number;

/**
 * Redmine's format_hours and its `timespan_format` setting: hours shown as a
 * decimal ("1.50") or as hours and minutes ("1:30", the default as in Redmine).
 */
final class Hours
{
    public const string DECIMAL = 'decimal';

    public const string MINUTES = 'minutes';

    /**
     * @var array<string, string>
     */
    public const array FORMATS = [
        self::DECIMAL => '小数(1.50)',
        self::MINUTES => '時:分(1:30)',
    ];

    /**
     * FORMATS with its labels translated, for display.
     *
     * @return array<string, string>
     */
    public static function formatLabels(): array
    {
        return [
            self::DECIMAL => __('小数(1.50)'),
            self::MINUTES => __('時:分(1:30)'),
        ];
    }

    public static function timespanFormat(): string
    {
        $format = (string) Setting::get('timespan_format', self::MINUTES);

        return array_key_exists($format, self::FORMATS) ? $format : self::MINUTES;
    }

    /**
     * @param  bool  $grouped  thousands separators in the decimal format, as the
     *                         on-screen totals have always shown them; CSV cells pass false
     */
    public static function format(float|int|string|null $hours, bool $grouped = true): string
    {
        if ($hours === null || $hours === '') {
            return '';
        }

        $hours = (float) $hours;

        if (self::timespanFormat() === self::MINUTES) {
            $minutes = (int) round(abs($hours) * 60);

            return ($hours < 0 && $minutes > 0 ? '-' : '').intdiv($minutes, 60).':'.sprintf('%02d', $minutes % 60);
        }

        return $grouped ? (string) Number::format($hours, precision: 2) : number_format($hours, 2, '.', '');
    }

    /**
     * Redmine's String#to_hours: reads "1.5", "1,5", "1.5h", "1:30", "1h30",
     * "1h 30m", "2 hours", "90m" and "45min" as hours. Null when the text is
     * none of these.
     */
    public static function parse(float|int|string|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return (float) $value;
        }

        $text = trim($value);

        if (preg_match('/^(\d+([.,]\d+)?)h?$/', $text, $match) === 1) {
            $text = $match[1];
        } elseif (preg_match('/^(\d+):(\d+)$/', $text, $match) === 1) {
            return (int) $match[1] + (int) $match[2] / 60;
        } elseif (preg_match('/^((\d+)\s*(h|hours?))?\s*((\d+)\s*(m|min)?)?$/i', $text, $match) === 1
            && (($match[1] ?? '') !== '' || ($match[4] ?? '') !== '')) {
            return (int) ($match[2] ?? 0) + (int) ($match[5] ?? 0) / 60;
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    /**
     * The value to validate for an hours input: the parsed hours when the
     * text reads as hours, otherwise the text itself so the numeric rule
     * reports it (Redmine's `h.to_hours || h`).
     */
    public static function normalizeInput(string $value): string
    {
        $hours = trim($value) === '' ? null : self::parse($value);

        return $hours === null ? $value : (string) round($hours, 2);
    }
}
