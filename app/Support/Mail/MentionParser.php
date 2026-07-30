<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Collection;

/**
 * Extracts `@login` mentions from raw Markdown source, matching Redmine's
 * `Redmine::Acts::Mentionable#get_mentioned_users` (lib/redmine/acts/
 * mentionable.rb): mentions only ever come from *newly added* text — an
 * edit that doesn't introduce a new `@login` token doesn't re-notify, and
 * a token that was already present before the edit is not re-counted.
 *
 * Deliberately a simplified subset of Redmine's own regex
 * (`(?:^|\W)@([A-Za-z0-9_\-@\.]*?)(?=(?=[[:punct:]][^A-Za-z0-9_\/])|\s|[[:punct:]]?$)`):
 * this scans for the login charset minus embedded `@` (a login containing
 * its own `@`, which User::LOGIN_FORMAT_REGEX technically allows, isn't
 * mentionable — an accepted narrowing rather than reproducing Redmine's
 * punctuation-lookahead boundary exactly), and only trims a single
 * trailing `.` (the common "mentioned at the end of a sentence" case)
 * rather than Redmine's full lookahead-based boundary logic.
 */
final class MentionParser
{
    private const MENTION_PATTERN = '/(?<![\w.\-])@([A-Za-z0-9_.\-]+)/';

    /**
     * @return array<int, string>
     */
    public static function extractLogins(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all(self::MENTION_PATTERN, self::stripNonScannableRegions($text), $matches);

        return Collection::make($matches[1])
            ->map(fn (string $login) => rtrim($login, '.'))
            ->filter(fn (string $login) => $login !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Logins present in $after but not already in $before — the "newly
     * added" diff mentionable.rb applies on every save, so editing text
     * without introducing a new @login doesn't re-notify anyone.
     *
     * @return array<int, string>
     */
    public static function newlyMentionedLogins(?string $before, ?string $after): array
    {
        return array_values(array_diff(self::extractLogins($after), self::extractLogins($before)));
    }

    /**
     * Fenced code blocks, inline code, and blockquote lines are excluded
     * from scanning — matches mentionable.rb stripping quoted-reply and
     * code regions before looking for @login tokens, so quoting someone
     * else's comment (which may itself contain a mention) doesn't
     * re-trigger a notification.
     */
    private static function stripNonScannableRegions(string $text): string
    {
        $text = preg_replace('/```.*?```/s', '', $text) ?? $text;
        $text = preg_replace('/`[^`]*`/', '', $text) ?? $text;

        return preg_replace('/^>.*$/m', '', $text) ?? $text;
    }
}
