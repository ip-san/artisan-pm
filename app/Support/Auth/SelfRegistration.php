<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Setting;

/**
 * The self_registration setting, read in one place so every caller agrees on its default:
 * Redmine's '2' (manual: an administrator activates new accounts, config/settings.yml).
 * A value an administrator has saved is used as is.
 */
final class SelfRegistration
{
    public const string DEFAULT = 'manual';

    /**
     * @return 'disabled'|'email'|'manual'|'automatic'|string
     */
    public static function mode(): string
    {
        return (string) Setting::get('self_registration', self::DEFAULT);
    }
}
