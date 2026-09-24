<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trusts the X-Forwarded-* headers of the proxies in `app.trusted_proxies`
 * (TRUSTED_PROXIES: `*` or a comma-separated list of IPs/CIDRs). Shared
 * hosts often terminate TLS at a front proxy and pass plain HTTP to PHP;
 * without this, Laravel sees http:// and builds http:// URLs and redirects.
 *
 * Read from config at request time (not in bootstrap/app.php, which runs
 * before the environment is loaded) so it also works with config:cache.
 */
final class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return parent::proxies();
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured)), fn (string $proxy): bool => $proxy !== ''));
    }
}
