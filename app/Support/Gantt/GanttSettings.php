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
}
