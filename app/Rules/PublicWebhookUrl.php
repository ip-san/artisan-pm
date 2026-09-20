<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook a user registers for themselves must not aim the server at its
 * own network (Redmine's Webhook::Executor rejects private and loopback
 * addresses when it delivers). The host is resolved when the hook is saved;
 * a host that does not resolve is let through, and the address is not
 * re-checked at delivery.
 */
final class PublicWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = parse_url((string) $value);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '') {
            $fail('URLはhttpまたはhttpsで指定してください。');

            return;
        }

        if (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            $fail('内部ネットワークのアドレスは指定できません。');

            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('内部ネットワークのアドレスは指定できません。');

                return;
            }
        }
    }
}
