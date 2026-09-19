<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Models\Setting;
use ValueError;

/**
 * Turns what an SCM hands back into valid UTF-8, the way Redmine's
 * Redmine::CodesetUtil.to_utf8_by_setting does: ASCII and text that is
 * already valid UTF-8 pass through; anything else is tried against the
 * administrator's `repositories_encodings` list (and, for commit logs, the
 * `commit_logs_encoding`) in order; what still fails has its invalid bytes
 * replaced rather than breaking the page.
 */
final class CodesetConverter
{
    public const string DEFAULT_LOG_ENCODING = 'UTF-8';

    public static function toUtf8(string $raw, ?string $preferredEncoding = null): string
    {
        // Converting UTF-8 to UTF-8 replaces each invalid byte with '?'.
        return self::convertStrictly($raw, $preferredEncoding) ?? mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }

    /**
     * Like toUtf8() but null when the text is not valid UTF-8 and none of
     * the candidate encodings fit it — so a caller can tell a legacy-encoded
     * text file from a binary one. Text containing NUL bytes is never
     * treated as legacy text.
     */
    public static function convertStrictly(string $raw, ?string $preferredEncoding = null): ?string
    {
        if ($raw === '' || mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        if (str_contains($raw, "\0")) {
            return null;
        }

        foreach ([$preferredEncoding, ...self::configuredEncodings()] as $encoding) {
            if ($encoding === null || $encoding === '' || strcasecmp($encoding, 'UTF-8') === 0) {
                continue;
            }

            // A name mbstring does not know (a typo in the setting) is skipped.
            if (! self::isKnownEncoding($encoding)) {
                continue;
            }

            if (mb_check_encoding($raw, $encoding)) {
                return mb_convert_encoding($raw, 'UTF-8', $encoding);
            }
        }

        return null;
    }

    /**
     * Whether mbstring can convert from `$name` (a canonical name or alias).
     */
    public static function isKnownEncoding(string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        if (in_array(strtolower($name), array_map('strtolower', mb_list_encodings()), true)) {
            return true;
        }

        try {
            return mb_encoding_aliases($name) !== [];
        } catch (ValueError) {
            return false;
        }
    }

    /**
     * Commit messages and committer names: the repository's own log encoding
     * first, else the commit_logs_encoding setting.
     */
    public static function logToUtf8(string $raw, ?string $repositoryEncoding = null): string
    {
        $logEncoding = filled($repositoryEncoding) ? $repositoryEncoding : Setting::get('commit_logs_encoding', self::DEFAULT_LOG_ENCODING);

        return self::toUtf8($raw, is_string($logEncoding) ? $logEncoding : null);
    }

    /**
     * @return array<int, string>
     */
    public static function configuredEncodings(): array
    {
        $raw = Setting::get('repositories_encodings', '');

        return is_string($raw)
            ? array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $encoding) => $encoding !== ''))
            : [];
    }
}
