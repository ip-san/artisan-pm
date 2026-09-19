<?php

declare(strict_types=1);

namespace App\Support\Preferences;

use App\Models\Setting;
use App\Models\User;

/**
 * Redmine's UserPreference: per-user options stored in `users.preferences`
 * with the defaults below. `hide_mail` and `auto_watch_on` start from the
 * site-wide `default_users_*` settings, which is how an administrator picks
 * what new accounts get.
 */
final class UserPreferences
{
    /**
     * @var array<string, string>
     */
    public const array COMMENTS_SORTING = ['asc' => '古い順', 'desc' => '新しい順'];

    /**
     * @var array<string, string>
     */
    public const array TEXTAREA_FONTS = ['' => '標準', 'monospace' => '等幅', 'proportional' => 'プロポーショナル'];

    /**
     * What "auto watch" can react to, by key (Redmine's auto_watch_on).
     *
     * @var array<string, string>
     */
    public const array AUTO_WATCH_ON = [
        'issue_created' => '自分が作成した課題',
        'issue_contributed_to' => '自分がコメント・更新した課題',
        'issue_assigned_to_me' => '自分が担当になった課題',
    ];

    /**
     * What accounts auto-watch until an administrator or the user says
     * otherwise: this app's original behavior (Redmine's own default is
     * just issue_created).
     *
     * @var array<int, string>
     */
    public const array DEFAULT_AUTO_WATCH_ON = ['issue_created', 'issue_assigned_to_me'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'comments_sorting' => 'asc',
            'warn_on_leaving_unsaved' => true,
            'textarea_font' => '',
            'hide_mail' => (bool) Setting::get('default_users_hide_mail', false),
            'notify_about_high_priority_issues' => false,
            'recently_used_projects' => 3,
            'recently_used_project_ids' => [],
            'auto_watch_on' => self::validAutoWatch(Setting::get('default_users_auto_watch_on', self::DEFAULT_AUTO_WATCH_ON)),
            'default_issue_query' => null,
        ];
    }

    public static function get(?User $user, string $key): mixed
    {
        $defaults = self::defaults();

        if ($user === null) {
            return $defaults[$key] ?? null;
        }

        $stored = is_array($user->preferences) ? $user->preferences : [];

        return array_key_exists($key, $stored) ? $stored[$key] : ($defaults[$key] ?? null);
    }

    /**
     * Validates and stores the given options, ignoring unknown keys.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(User $user, array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $clean[$key] = match ($key) {
                'comments_sorting' => array_key_exists((string) $value, self::COMMENTS_SORTING) ? $value : 'asc',
                'warn_on_leaving_unsaved', 'hide_mail', 'notify_about_high_priority_issues' => (bool) $value,
                'textarea_font' => array_key_exists((string) $value, self::TEXTAREA_FONTS) ? (string) $value : '',
                'auto_watch_on' => self::validAutoWatch($value),
                'default_issue_query' => filled($value) ? (int) $value : null,
                'recently_used_projects' => max(0, min(10, (int) $value)),
                'recently_used_project_ids' => array_values(array_unique(array_map('intval', is_array($value) ? $value : []))),
                default => null,
            };

            if (! array_key_exists($key, self::defaults())) {
                unset($clean[$key]);
            }
        }

        $user->preferences = [...(is_array($user->preferences) ? $user->preferences : []), ...$clean];
        $user->save();
    }

    /**
     * The Tailwind font class for a text area, per the user's textarea_font
     * (the wiki editor is monospace regardless of the default).
     */
    public static function textareaClass(?User $user, string $fallback = ''): string
    {
        return match (self::get($user, 'textarea_font')) {
            'monospace' => 'font-mono',
            'proportional' => 'font-sans',
            default => $fallback,
        };
    }

    /**
     * Alpine attributes for a form that should warn before the page is left
     * with unsaved input: none unless the user keeps warn_on_leaving_unsaved
     * on. Submitting clears the flag, so saving never triggers the prompt.
     */
    public static function unsavedWarningAttributes(?User $user): string
    {
        if (! self::get($user, 'warn_on_leaving_unsaved')) {
            return '';
        }

        return 'x-data="{ dirty: false }" x-on:input="dirty = true" x-on:submit="dirty = false" '
            .'x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = \'\'; }" data-unsaved-warning';
    }

    /**
     * @return array<int, string>
     */
    private static function validAutoWatch(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter(array_keys(self::AUTO_WATCH_ON), fn (string $key) => in_array($key, $value, true)))
            : [];
    }
}
