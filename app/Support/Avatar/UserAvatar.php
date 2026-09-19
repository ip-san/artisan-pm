<?php

declare(strict_types=1);

namespace App\Support\Avatar;

use App\Models\Setting;
use App\Models\User;

/**
 * Redmine's avatars: with `gravatar_enabled` a user's Gravatar (chosen by a
 * hash of their e-mail address, with `gravatar_default` as the fallback
 * style), otherwise a coloured circle with their initials. Nothing leaves
 * the server unless Gravatar is switched on.
 */
final class UserAvatar
{
    /**
     * The values `gravatar_default` may take: what Gravatar draws for an
     * address it does not know, or `initials` for our own initials circle.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_STYLES = [
        '' => 'Gravatar標準',
        'mm' => 'ミステリーパーソン',
        'identicon' => 'Identicon',
        'monsterid' => 'Monster',
        'wavatar' => 'Wavatar',
        'retro' => 'Retro',
        'robohash' => 'Robohash',
        'initials' => 'イニシャル',
    ];

    public static function gravatarEnabled(): bool
    {
        return (bool) Setting::get('gravatar_enabled', false);
    }

    public static function defaultStyle(): string
    {
        $style = (string) Setting::get('gravatar_default', 'identicon');

        return array_key_exists($style, self::DEFAULT_STYLES) ? $style : 'identicon';
    }

    /**
     * The Gravatar URL for `$email` at `$size` pixels (asked for at twice
     * the size so it stays sharp on dense screens). With the `initials`
     * style Gravatar is asked to answer 404 for unknown addresses, so the
     * initials circle showing behind stays visible.
     */
    public static function gravatarUrl(string $email, int $size): string
    {
        $default = self::defaultStyle();
        $query = ['s' => $size * 2, 'd' => $default === 'initials' ? '404' : $default];

        if ($default === '') {
            unset($query['d']);
        }

        return 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($email))).'?'.http_build_query($query);
    }

    /**
     * Up to two capital letters: the first letters of the first and last
     * word of the name, else the first two letters.
     */
    public static function initials(User|string $user): string
    {
        $name = trim($user instanceof User ? $user->name : $user);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, mb_strlen($words[0]) > 1 && preg_match('/^[\x20-\x7e]+$/', $words[0]) === 1 ? 2 : 1));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr(end($words), 0, 1));
    }

    /**
     * A stable colour per user, derived from their name: hue only, so every
     * circle reads well with white text.
     */
    public static function color(User|string $user): string
    {
        $name = $user instanceof User ? $user->name : $user;
        $hue = hexdec(substr(md5(mb_strtolower(trim($name))), 0, 4)) % 360;

        return "hsl({$hue}, 45%, 42%)";
    }
}
