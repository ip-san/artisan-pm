<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Setting;

/**
 * The 0-100 percentages offered wherever a progress ratio is picked —
 * Redmine's `(0..100).step(Setting.issue_done_ratio_interval)`, which the
 * progressbar custom field format also uses with its own `ratio_interval`.
 * The interval only shapes the choices; a stored value that is not a
 * multiple of it stays valid and is kept as an extra option.
 */
final class DoneRatioSteps
{
    /** @var array<int, int> Redmine offers exactly these two. */
    public const array INTERVALS = [5, 10];

    public const int DEFAULT_INTERVAL = 10;

    /**
     * The configured site-wide interval, falling back to the default when
     * the stored value is missing or not one of INTERVALS.
     */
    public static function interval(): int
    {
        $stored = (int) Setting::get('issue_done_ratio_interval', self::DEFAULT_INTERVAL);

        return in_array($stored, self::INTERVALS, true) ? $stored : self::DEFAULT_INTERVAL;
    }

    /**
     * @return array<int, int> ascending; $current is added in place when it
     *                         is not one of the steps
     */
    public static function options(?int $interval = null, ?int $current = null): array
    {
        $interval = in_array($interval, self::INTERVALS, true) ? $interval : self::interval();
        $steps = range(0, 100, $interval);

        if ($current !== null && $current >= 0 && $current <= 100 && ! in_array($current, $steps, true)) {
            $steps[] = $current;
            sort($steps);
        }

        return $steps;
    }
}
