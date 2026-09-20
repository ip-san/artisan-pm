<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * While a callback runs inside `during()`, the notification listeners send
 * nothing — Redmine's `no_notification` mail handler option, which empties
 * notified_events for the time a received mail is processed.
 */
final class MailSuppression
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function during(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
