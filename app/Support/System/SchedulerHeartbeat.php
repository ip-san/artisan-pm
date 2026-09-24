<?php

declare(strict_types=1);

namespace App\Support\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * When `schedule:run` last ran: a scheduled task records the time every
 * minute and Admin → Information reads it, so an administrator can tell
 * whether the cron line (incoming mail, repository fetch, the database
 * queue) is set up and still running. Kept in the cache store, which every
 * supported deployment (file, database, Redis) shares between the cron and
 * the web server.
 */
final class SchedulerHeartbeat
{
    public const string CACHE_KEY = 'system:scheduler-heartbeat';

    /**
     * Minutes without a run after which the scheduler is reported as stopped
     * (it runs every minute).
     */
    public const int STALE_AFTER_MINUTES = 5;

    public static function record(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }

    public static function lastRun(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * 'never' (no run recorded), 'stale' (the last run is too old) or 'ok'.
     */
    public static function status(): string
    {
        $lastRun = self::lastRun();

        if ($lastRun === null) {
            return 'never';
        }

        return $lastRun->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)) ? 'stale' : 'ok';
    }
}
