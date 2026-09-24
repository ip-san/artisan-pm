<?php

declare(strict_types=1);

namespace App\Support\Format;

use App\Models\User;
use App\Support\Locale\TimeZones;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;

/**
 * Redmine's format_date / format_time and User#today: how dates and times
 * are shown to the person looking at them. Times are stored in UTC and only
 * turned into the viewer's zone here, for display; date-only values
 * (start_date, due_date, spent_on…) are never shifted.
 *
 * The viewer is the signed-in user, or — while a mail is rendered for its
 * recipient — the user given to asViewer(), the way a notification is built
 * in its recipient's language.
 */
final class DateTimes
{
    private static ?User $viewerOverride = null;

    private static bool $viewerOverridden = false;

    /**
     * Runs $callback with dates and times shown as $viewer sees them.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asViewer(?User $viewer, Closure $callback): mixed
    {
        [$previous, $wasOverridden] = [self::$viewerOverride, self::$viewerOverridden];
        [self::$viewerOverride, self::$viewerOverridden] = [$viewer, true];

        try {
            return $callback();
        } finally {
            [self::$viewerOverride, self::$viewerOverridden] = [$previous, $wasOverridden];
        }
    }

    public static function viewer(): ?User
    {
        if (self::$viewerOverridden) {
            return self::$viewerOverride;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The viewer's zone: theirs, else `default_users_time_zone`, else the server's.
     */
    public static function timeZone(?User $viewer = null): string
    {
        return TimeZones::forUser($viewer ?? self::viewer());
    }

    /**
     * Redmine's User#today: the current day where the viewer is, at midnight
     * in their zone. `->toDateString()` gives the date to default a date field to.
     */
    public static function today(?User $viewer = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::timeZone($viewer))->startOfDay();
    }

    /**
     * A stored time as a copy in the viewer's zone (the attribute itself is
     * never changed, so saving the model afterwards keeps the UTC value).
     */
    public static function local(?DateTimeInterface $value, ?User $viewer = null): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::instance($value)->setTimezone(self::timeZone($viewer));
    }

    /**
     * A date-only value (a date column, or a `Y-m-d` string), as it is: no zone.
     */
    public static function date(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);

        return $date->translatedFormat(self::dateFormat());
    }

    /**
     * The day a stored time falls on for the viewer, formatted like date().
     */
    public static function dateOf(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::local($value, $viewer)?->translatedFormat(self::dateFormat());
    }

    /**
     * A stored time's date and time of day for the viewer.
     */
    public static function dateTime(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::local($value, $viewer)?->translatedFormat(self::dateFormat().' '.self::timeFormat());
    }

    /**
     * A stored time's time of day for the viewer.
     */
    public static function time(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::local($value, $viewer)?->translatedFormat(self::timeFormat());
    }

    /**
     * Whole days from the viewer's today to a date-only value: negative when
     * it has passed, 0 on the day itself (Redmine's `date < User.current.today`).
     */
    public static function daysFromToday(DateTimeInterface|string $date, ?User $viewer = null): int
    {
        $day = CarbonImmutable::parse($date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10));

        return (int) round(CarbonImmutable::parse(self::today($viewer)->toDateString())->diffInDays($day));
    }

    /**
     * The first and last moment (UTC) of a day in the viewer's zone, for
     * comparing a date typed into a filter with a stored time — Redmine's
     * Query#date_clause.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(string $date, ?User $viewer = null): array
    {
        $start = CarbonImmutable::parse(substr($date, 0, 10), self::timeZone($viewer))->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }

    public static function dateFormat(): string
    {
        return 'Y-m-d';
    }

    public static function timeFormat(): string
    {
        return 'H:i';
    }
}
