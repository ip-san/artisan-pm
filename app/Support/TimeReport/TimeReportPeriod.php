<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use Illuminate\Support\Carbon;

/**
 * The column axis for the multi-dimensional time report — mirrors
 * Redmine::Helpers::TimeReport's `@columns` ('year'/'month'/'week'/'day').
 */
enum TimeReportPeriod: string
{
    case Year = 'year';
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    public function label(): string
    {
        return match ($this) {
            self::Year => __('年'),
            self::Month => __('月'),
            self::Week => __('週'),
            self::Day => __('日'),
        };
    }

    /**
     * The bucket key for a given date — e.g. '2026-07' for month,
     * '2026-30' (ISO week) for week — matching Redmine's own
     * "#{year}-#{month}" / "#{cwyear}-#{cweek}" period-key format.
     */
    public function keyFor(Carbon $date): string
    {
        return match ($this) {
            self::Year => (string) $date->year,
            self::Month => sprintf('%d-%02d', $date->year, $date->month),
            self::Week => sprintf('%d-%02d', $date->isoWeekYear, $date->isoWeek),
            self::Day => $date->toDateString(),
        };
    }

    /**
     * A human-readable label for a bucket key produced by keyFor().
     */
    public function labelFor(string $key): string
    {
        return match ($this) {
            self::Year => $key,
            self::Month => $key,
            self::Week => __(':year年第:week週', array_combine(['year', 'week'], array_pad(explode('-', $key, 2), 2, ''))),
            self::Day => $key,
        };
    }

    public function next(Carbon $date): Carbon
    {
        return match ($this) {
            self::Year => $date->clone()->addYear()->startOfYear(),
            self::Month => $date->clone()->addMonth()->startOfMonth(),
            self::Week => $date->clone()->addWeek()->startOfWeek(Carbon::MONDAY),
            self::Day => $date->clone()->addDay(),
        };
    }

    public function startOf(Carbon $date): Carbon
    {
        return match ($this) {
            self::Year => $date->clone()->startOfYear(),
            self::Month => $date->clone()->startOfMonth(),
            self::Week => $date->clone()->startOfWeek(Carbon::MONDAY),
            self::Day => $date->clone(),
        };
    }
}
