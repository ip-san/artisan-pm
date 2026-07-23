<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Matches Redmine's Setting.self_registration: 'disabled' rejects the
     * submission outright (mirroring the account/register page's own
     * redirect-away-if-disabled check, as defense in depth against a
     * direct POST bypassing that), 'manual' creates the account locked
     * pending admin approval (UserStatus::Registered, matching Redmine's
     * STATUS_REGISTERED), and 'automatic' activates it immediately — the
     * app's prior, only behavior. Redmine's third mode, email-confirmation
     * activation, is intentionally not implemented: this app has no
     * outbound notification email system yet.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $mode = Setting::get('self_registration', 'automatic');

        if ($mode === 'disabled') {
            throw ValidationException::withMessages([
                'email' => 'このサイトではアカウント登録を受け付けていません。',
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->domainAllowed((string) $value)) {
                        $fail('このメールアドレスのドメインでは登録できません。');
                    }
                },
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'status' => $mode === 'manual' ? UserStatus::Registered->value : UserStatus::Active->value,
        ]);
    }

    /**
     * Matches Redmine's EmailAddress.valid_domain?: a denied match rejects
     * outright regardless of the allow list; otherwise, a non-empty allow
     * list acts as a whitelist. A domain entry starting with "." matches
     * that domain and any subdomain of it (Redmine's own domain_in?),
     * everything else is an exact, case-insensitive match. Only applied
     * to self-registration here — unlike Redmine, where EmailAddress
     * validates this on every user's email (including admin-created
     * accounts), this app's admin-facing user form doesn't enforce it, a
     * deliberately scoped-down starting point.
     */
    private function domainAllowed(string $email): bool
    {
        $domain = strtolower((string) strrchr($email, '@'));
        $domain = ltrim($domain, '@');

        if ($domain === '') {
            return true;
        }

        $denied = self::parseDomainList(Setting::get('email_domains_denied', ''));
        $allowed = self::parseDomainList(Setting::get('email_domains_allowed', ''));

        if ($denied !== [] && self::domainMatchesAny($domain, $denied)) {
            return false;
        }

        if ($allowed !== [] && ! self::domainMatchesAny($domain, $allowed)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function parseDomainList(string $raw): array
    {
        return collect(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $domain) => strtolower($domain))
            ->all();
    }

    /**
     * @param  array<int, string>  $domains
     */
    private static function domainMatchesAny(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            if (str_starts_with($candidate, '.') ? str_ends_with($domain, $candidate) : $domain === $candidate) {
                return true;
            }
        }

        return false;
    }
}
