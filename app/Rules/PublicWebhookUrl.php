<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Webhooks\WebhookEndpoint;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook a user registers for themselves must not aim the server at its
 * own network (Redmine's WebhookEndpointValidator). The host is resolved when
 * the hook is saved; a host that does not resolve yet is let through, since
 * every delivery resolves and checks it again (DeliverWebhookJob).
 */
final class PublicWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = WebhookEndpoint::parse((string) $value);

        if ($parts === null) {
            $fail(__('URLはhttpまたはhttpsで指定してください。'));

            return;
        }

        if (WebhookEndpoint::isLocalhostName($parts['host'])) {
            $fail(__('内部ネットワークのアドレスは指定できません。'));

            return;
        }

        foreach (WebhookEndpoint::addressesOf($parts['host']) as $address) {
            if (! WebhookEndpoint::isAllowedAddress($address, publicOnly: true)) {
                $fail(__('内部ネットワークのアドレスは指定できません。'));

                return;
            }
        }
    }
}
