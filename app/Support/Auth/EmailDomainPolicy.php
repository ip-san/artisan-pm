<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Setting;

/**
 * Redmine's EmailAddress.valid_domain?, driven by the email_domains_allowed
 * and email_domains_denied settings: a denied match rejects outright
 * regardless of the allow list; otherwise a non-empty allow list acts as a
 * whitelist. A domain entry starting with "." matches any subdomain of it
 * but not the bare domain itself (Redmine's domain_in? is a plain
 * end_with?), anything else is an exact, case-insensitive match.
 */
final class EmailDomainPolicy
{
    public static function allows(string $email): bool
    {
        $domain = ltrim(strtolower((string) strrchr($email, '@')), '@');

        if ($domain === '') {
            return true;
        }

        $denied = self::parseList((string) Setting::get('email_domains_denied', ''));
        $allowed = self::parseList((string) Setting::get('email_domains_allowed', ''));

        if ($denied !== [] && self::matchesAny($domain, $denied)) {
            return false;
        }

        return $allowed === [] || self::matchesAny($domain, $allowed);
    }

    /**
     * @return array<int, string>
     */
    private static function parseList(string $raw): array
    {
        return collect(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $domain) => strtolower($domain))
            ->all();
    }

    /**
     * @param  array<int, string>  $domains
     */
    private static function matchesAny(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            if (str_starts_with($candidate, '.') ? str_ends_with($domain, $candidate) : $domain === $candidate) {
                return true;
            }
        }

        return false;
    }
}
