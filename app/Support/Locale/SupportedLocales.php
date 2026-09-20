<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The display languages and how one is picked for a request, in Redmine's
 * order (ApplicationController#set_localization): the signed-in user's own
 * language, unless the administrator forces the default for signed-in users;
 * for a visitor, the browser's Accept-Language, unless forced; otherwise
 * the `default_language` setting. Each language has a `lang/{code}.json`.
 */
final class SupportedLocales
{
    /**
     * @return array<string, string> code => the language's own name
     */
    public static function all(): array
    {
        return ['ja' => '日本語', 'en' => 'English'];
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::all());
    }

    /**
     * What the app shows when nothing else applies: the setting, or the
     * configured locale (English until an administrator picks otherwise).
     */
    public static function default(): string
    {
        $configured = Setting::get('default_language');

        return self::isSupported($configured) ? $configured : (self::isSupported(config('app.locale')) ? config('app.locale') : 'en');
    }

    public static function resolve(?User $user, Request $request): string
    {
        if ($user !== null) {
            $own = $user->language;

            return self::isSupported($own) && ! Setting::get('force_default_language_for_loggedin', false) ? $own : self::default();
        }

        if (! Setting::get('force_default_language_for_anonymous', false)) {
            foreach ($request->getLanguages() as $accepted) {
                $code = strtolower(substr($accepted, 0, 2));

                if (self::isSupported($code)) {
                    return $code;
                }
            }
        }

        return self::default();
    }
}
