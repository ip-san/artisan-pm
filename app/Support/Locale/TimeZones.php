<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Models\Setting;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The time zones a user can see times in (Redmine's UserPreference#time_zone
 * and `default_users_time_zone`), as IANA identifiers. A user who has not
 * picked one gets the setting, then the server's zone (`app.timezone`, UTC)
 * — like SupportedLocales, the default applies to everyone who has not
 * chosen, not only to users created after it was set.
 */
final class TimeZones
{
    /**
     * @var array<string, true>|null
     */
    private static ?array $identifiers = null;

    public static function isSupported(?string $identifier): bool
    {
        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);

        return $identifier !== null && isset(self::$identifiers[$identifier]);
    }

    /**
     * The `default_users_time_zone` setting, or the server's zone.
     */
    public static function default(): string
    {
        $configured = Setting::get('default_users_time_zone', '');

        return self::isSupported($configured) ? $configured : (string) config('app.timezone', 'UTC');
    }

    public static function forUser(?User $user): string
    {
        $own = $user?->time_zone;

        return self::isSupported($own) ? $own : self::default();
    }

    /**
     * Every zone labelled with its current offset, ordered by offset then
     * name — "(UTC+09:00) Asia/Tokyo" — for a select.
     *
     * @return array<string, string> identifier => label
     */
    public static function options(): array
    {
        $now = new DateTimeImmutable('now');
        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $zones[] = [$identifier, (new DateTimeZone($identifier))->getOffset($now)];
        }

        usort($zones, fn (array $a, array $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $options = [];

        foreach ($zones as [$identifier, $offset]) {
            $options[$identifier] = sprintf('(UTC%s%02d:%02d) %s', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), $identifier);
        }

        return $options;
    }
}
