<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Redmine's `host_name` and `protocol` settings: the address links in
 * e-mails and other output generated outside a web request (queue workers,
 * scheduled jobs) point to. Without them those links follow `APP_URL`.
 * Web requests keep using the address the browser actually reached.
 */
final class PublicUrl
{
    public const string DEFAULT_PROTOCOL = 'http';

    /**
     * host, optionally with a port and a path prefix — what may be typed into
     * the setting.
     */
    public const string HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/i';

    /**
     * "https://mail.example.com/redmine" — or null while no host is set.
     */
    public static function root(): ?string
    {
        $host = trim((string) Setting::get('host_name', ''), " \t\n\r\0\x0B/");

        if ($host === '') {
            return null;
        }

        $protocol = Setting::get('protocol', self::DEFAULT_PROTOCOL) === 'https' ? 'https' : 'http';

        return "{$protocol}://{$host}";
    }

    /**
     * Points URL generation at the configured address. Called at start-up
     * of console processes and before each queued job, so a changed setting
     * reaches a long-running worker too. Never throws: a database that is
     * not there yet (fresh install, running migrations) just means "not set".
     */
    public static function apply(): void
    {
        try {
            $root = self::root();
        } catch (Throwable) {
            return;
        }

        if ($root === null) {
            return;
        }

        URL::forceRootUrl($root);
        URL::forceScheme(str_starts_with($root, 'https') ? 'https' : 'http');
    }
}
