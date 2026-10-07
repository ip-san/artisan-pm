<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterOperator;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;
use Carbon\CarbonImmutable;

/**
 * The span of days a relative date operator stands for, as Redmine's date_clause does: today comes
 * from the viewer's time zone, and a week runs from the site's start_of_week (Sunday unless set).
 * Either end may be open (null): "in more than 3 days" has no last day.
 */
final class RelativeDateRange
{
    /**
     * @param  array<int, mixed>  $values  the days, for the operators that take them
     * @return array{0: string|null, 1: string|null}|null first and last day (Y-m-d), or null for an operator that is not relative
     */
    public static function for(FilterOperator $operator, array $values, ?User $viewer = null): ?array
    {
        $today = DateTimes::today($viewer);
        $days = max(0, (int) ($values[0] ?? 0));
        $week = self::weekStart($today);

        $range = match ($operator) {
            FilterOperator::Today => [$today, $today],
            FilterOperator::Yesterday => [$today->subDay(), $today->subDay()],
            FilterOperator::Tomorrow => [$today->addDay(), $today->addDay()],
            FilterOperator::ThisWeek => [$week, $week->addDays(6)],
            FilterOperator::LastWeek => [$week->subWeek(), $week->subDay()],
            FilterOperator::LastTwoWeeks => [$week->subWeeks(2), $week->subDay()],
            FilterOperator::NextWeek => [$week->addWeek(), $week->addWeeks(2)->subDay()],
            FilterOperator::ThisMonth => [$today->startOfMonth(), $today->endOfMonth()],
            FilterOperator::LastMonth => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            FilterOperator::NextMonth => [$today->addMonthNoOverflow()->startOfMonth(), $today->addMonthNoOverflow()->endOfMonth()],
            FilterOperator::ThisYear => [$today->startOfYear(), $today->endOfYear()],
            FilterOperator::InTheLastDays => [$today->subDays($days), $today],
            FilterOperator::DaysAgo => [$today->subDays($days), $today->subDays($days)],
            FilterOperator::MoreThanDaysAgo => [null, $today->subDays($days)],
            FilterOperator::InDays => [$today->addDays($days), $today->addDays($days)],
            FilterOperator::InMoreThanDays => [$today->addDays($days), null],
            FilterOperator::InTheNextDays => [$today, $today->addDays($days)],
            default => null,
        };

        return $range === null ? null : [$range[0]?->toDateString(), $range[1]?->toDateString()];
    }

    /**
     * The first day of today's week.
     */
    private static function weekStart(CarbonImmutable $today): CarbonImmutable
    {
        $first = (int) Setting::get('start_of_week', CarbonImmutable::SUNDAY);

        return $today->subDays(($today->dayOfWeek - $first + 7) % 7);
    }
}
