<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Redmine's Redmine::Utils::DateCalculation: date arithmetic that skips the
 * site's non-working weekdays (`non_working_week_days`, ISO numbers 1 = Monday
 * … 7 = Sunday). With none configured — this app's default, which keeps its
 * former calendar-day scheduling — every day is a working day. A setting that
 * lists all seven days is ignored, as in Redmine.
 */
final class WorkingDays
{
    /**
     * @return array<int, int>
     */
    public static function nonWorkingWeekDays(): array
    {
        $days = Setting::get('non_working_week_days', []);

        if (! is_array($days)) {
            return [];
        }

        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn (int $day) => $day >= 1 && $day <= 7)));

        return count($days) < 7 ? $days : [];
    }

    public static function isWorkingDay(CarbonInterface $date): bool
    {
        return ! in_array($date->dayOfWeekIso, self::nonWorkingWeekDays(), true);
    }

    /**
     * Working days in the half-open span from $from up to $to: a Monday to
     * the following Monday is 5 with a Saturday/Sunday weekend.
     */
    public static function between(CarbonInterface $from, CarbonInterface $to): int
    {
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false);

        if ($days <= 0) {
            return 0;
        }

        $nonWorking = self::nonWorkingWeekDays();
        $weeks = intdiv($days, 7);
        $result = $weeks * (7 - count($nonWorking));
        $daysLeft = $days - $weeks * 7;
        $startWeekday = $from->dayOfWeekIso;

        for ($offset = 0; $offset < $daysLeft; $offset++) {
            if (! in_array((($startWeekday + $offset - 1) % 7) + 1, $nonWorking, true)) {
                $result++;
            }
        }

        return $result;
    }

    /**
     * $date moved on by $workingDays working days; the result is always a
     * working day when any days were added.
     */
    public static function add(CarbonInterface $date, int $workingDays): CarbonInterface
    {
        if ($workingDays <= 0) {
            return $date->copy();
        }

        $nonWorking = self::nonWorkingWeekDays();
        $weeks = intdiv($workingDays, 7 - count($nonWorking));
        $elapsed = $weeks * 7;
        $daysLeft = $workingDays - $weeks * (7 - count($nonWorking));
        $weekday = $date->dayOfWeekIso;

        while ($daysLeft > 0) {
            $weekday++;

            if (! in_array((($weekday - 1) % 7) + 1, $nonWorking, true)) {
                $daysLeft--;
            }

            $elapsed++;
        }

        return self::nextWorkingDate($date->copy()->addDays($elapsed));
    }

    /**
     * $date itself when it is a working day, otherwise the next one.
     */
    public static function nextWorkingDate(CarbonInterface $date): CarbonInterface
    {
        $nonWorking = self::nonWorkingWeekDays();
        $weekday = $date->dayOfWeekIso;
        $days = 0;

        while (in_array((($weekday + $days - 1) % 7) + 1, $nonWorking, true)) {
            $days++;
        }

        return $date->copy()->addDays($days);
    }
}
