<?php

declare(strict_types=1);

namespace App\Support\Format;

use App\Models\Setting;
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
    /**
     * Redmine's Setting::DATE_FORMATS (stored as Redmine stores them) and the
     * PHP format each means. Month names follow the display language.
     *
     * @var array<string, string>
     */
    public const array DATE_FORMATS = [
        '%Y-%m-%d' => 'Y-m-d',
        '%d/%m/%Y' => 'd/m/Y',
        '%d.%m.%Y' => 'd.m.Y',
        '%d-%m-%Y' => 'd-m-Y',
        '%m/%d/%Y' => 'm/d/Y',
        '%d %b %Y' => 'd M Y',
        '%d %B %Y' => 'd F Y',
        '%b %d, %Y' => 'M d, Y',
        '%B %d, %Y' => 'F d, Y',
    ];

    /**
     * Each date format without its day, for month headings (the gantt chart,
     * the time report, the calendar).
     *
     * @var array<string, string>
     */
    public const array MONTH_FORMATS = [
        'Y-m-d' => 'Y-m',
        'd/m/Y' => 'm/Y',
        'd.m.Y' => 'm.Y',
        'd-m-Y' => 'm-Y',
        'm/d/Y' => 'm/Y',
        'd M Y' => 'M Y',
        'd F Y' => 'F Y',
        'M d, Y' => 'M Y',
        'F d, Y' => 'F Y',
    ];

    /**
     * Redmine's Setting::TIME_FORMATS.
     *
     * @var array<string, string>
     */
    public const array TIME_FORMATS = [
        '%H:%M' => 'H:i',
        '%I:%M %p' => 'h:i A',
    ];

    /**
     * What an empty `date_format` / `time_format` means here: ISO dates and a
     * 24-hour clock, as this app has always shown (Redmine's empty setting
     * follows the language instead).
     */
    public const string DEFAULT_DATE_FORMAT = 'Y-m-d';

    public const string DEFAULT_TIME_FORMAT = 'H:i';

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

        return self::localized($date)->translatedFormat(self::dateFormat());
    }

    /**
     * The month of a date-only value in the date format without its day
     * (`2026-09` by default).
     */
    public static function month(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);

        return self::localized($date)->translatedFormat(self::MONTH_FORMATS[self::dateFormat()] ?? 'Y-m');
    }

    /**
     * Whether the `date_format` setting chooses a format (empty keeps the
     * app's own ISO dates and headings).
     */
    public static function hasDateFormat(): bool
    {
        return array_key_exists((string) Setting::get('date_format', ''), self::DATE_FORMATS);
    }

    /**
     * The day a stored time falls on for the viewer, formatted like date().
     */
    public static function dateOf(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::localized(self::local($value, $viewer))?->translatedFormat(self::dateFormat());
    }

    /**
     * A stored time's date and time of day for the viewer.
     */
    public static function dateTime(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::localized(self::local($value, $viewer))?->translatedFormat(self::dateFormat().' '.self::timeFormat());
    }

    /**
     * A stored time's time of day for the viewer.
     */
    public static function time(?DateTimeInterface $value, ?User $viewer = null): ?string
    {
        return self::localized(self::local($value, $viewer))?->translatedFormat(self::timeFormat());
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

    /**
     * The `date_format` setting as a PHP format.
     */
    public static function dateFormat(): string
    {
        return self::DATE_FORMATS[(string) Setting::get('date_format', '')] ?? self::DEFAULT_DATE_FORMAT;
    }

    /**
     * The `time_format` setting as a PHP format.
     */
    public static function timeFormat(): string
    {
        return self::TIME_FORMATS[(string) Setting::get('time_format', '')] ?? self::DEFAULT_TIME_FORMAT;
    }

    /**
     * The settings' choices, each shown as today (or now) looks in it, then
     * its pattern — Redmine's date_format_setting_options.
     *
     * @return array<string, string> stored value => label
     */
    public static function dateFormatOptions(): array
    {
        $today = self::localized(self::today());
        $options = ['' => __('既定(:example)', ['example' => $today->translatedFormat(self::DEFAULT_DATE_FORMAT)])];

        foreach (self::DATE_FORMATS as $stored => $format) {
            $pattern = strtr(str_replace('%', '', $stored), ['d' => 'dd', 'm' => 'mm', 'Y' => 'yyyy']);
            $options[$stored] = $today->translatedFormat($format)." ({$pattern})";
        }

        return $options;
    }

    /**
     * @return array<string, string> stored value => label
     */
    public static function timeFormatOptions(): array
    {
        $now = self::localized(self::local(CarbonImmutable::now()));
        $options = ['' => __('既定(:example)', ['example' => $now->translatedFormat(self::DEFAULT_TIME_FORMAT)])];

        foreach (self::TIME_FORMATS as $stored => $format) {
            $options[$stored] = $now->translatedFormat($format);
        }

        return $options;
    }

    /**
     * Month names and AM/PM in the display language (a mail is rendered in
     * its recipient's).
     */
    private static function localized(?CarbonImmutable $value): ?CarbonImmutable
    {
        return $value?->locale(app()->getLocale());
    }
}
