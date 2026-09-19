<?php

declare(strict_types=1);

namespace App\Support\Format;

use App\Models\Setting;
use Illuminate\Support\Number;

/**
 * Redmine's format_hours and its `timespan_format` setting: hours shown as a
 * decimal ("1.50", the default) or as hours and minutes ("1:30").
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

    public static function timespanFormat(): string
    {
        $format = (string) Setting::get('timespan_format', self::DECIMAL);

        return array_key_exists($format, self::FORMATS) ? $format : self::DECIMAL;
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
}
