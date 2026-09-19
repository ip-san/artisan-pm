<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Auth\EmailDomainPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Redmine validates the email domain on every user's address, however the
 * account is created or edited. $unchangedFrom skips the check while an
 * existing address is kept as it is, so a domain restriction added later
 * never blocks an unrelated profile edit.
 */
final class AllowedEmailDomain implements ValidationRule
{
    public function __construct(private readonly ?string $unchangedFrom = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = (string) $value;

        if ($this->unchangedFrom !== null && strcasecmp($email, $this->unchangedFrom) === 0) {
            return;
        }

        if (! EmailDomainPolicy::allows($email)) {
            $fail('このメールアドレスのドメインでは登録できません。');
        }
    }
}
