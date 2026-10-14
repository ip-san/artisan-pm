<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use App\Models\Setting;

/**
 * Redmine's gantt_items_limit / gantt_months_limit, shared by the project
 * and cross-project charts.
 */
final class GanttSettings
{
    /**
     * At most this many rows are drawn (0 = every row).
     */
    public static function itemsLimit(): int
    {
        return max(0, (int) Setting::get('gantt_items_limit', 500));
    }

    /**
     * The chart spans at most this many months from its first start date
     * (0 = as long as the issues need).
     */
    public static function monthsLimit(): int
    {
        return max(0, (int) Setting::get('gantt_months_limit', 24));
    }

    /**
     * The most months an explicit period may span (Redmine's
     * Helpers::Gantt months, clamped to gantt_months_limit — or to 24 when
     * there is no limit, as Redmine further clamps a limit-less value).
     */
    public static function periodMonthsCeiling(): int
    {
        return self::monthsLimit() > 0 ? self::monthsLimit() : 24;
    }
}
