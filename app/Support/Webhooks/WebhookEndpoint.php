<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use Closure;

/**
 * Decides whether a webhook URL may be called and to which addresses — the
 * counterpart of Redmine's WebhookEndpointValidator. A URL is safe when its
 * scheme is http(s), its port is not one browsers refuse (the WHATWG "bad
 * ports"), and every address its host resolves to (A and AAAA) is allowed.
 *
 * Every hook is refused loopback, link-local, unspecified (0.0.0.0, ::) and
 * multicast addresses, as Redmine always does. A hook a user registers for
 * themselves is also refused private and reserved ranges; a hook an
 * administrator registers may reach the internal network, as it always could.
 *
 * The same check runs when a user saves a hook and again when any hook is
 * delivered (DeliverWebhookJob), where the connection is pinned to the
 * checked address so a DNS answer that changes in between (DNS rebinding)
 * cannot redirect the request.
 */
final class WebhookEndpoint
{
    /**
     * @var list<int>
     */
    public const array BAD_PORTS = [
        1, 7, 9, 11, 13, 15, 17, 19, 20, 21, 22, 23, 25, 37, 42, 43, 53, 69, 77, 79, 87, 95, 101, 102, 103, 104,
        109, 110, 111, 113, 115, 117, 119, 123, 135, 137, 139, 143, 161, 179, 389, 427, 465, 512, 513, 514, 515,
        526, 530, 531, 532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993, 995, 1719, 1720, 1723, 2049,
        3659, 4045, 4190, 5060, 5061, 6000, 6566, 6665, 6666, 6667, 6668, 6669, 6679, 6697, 10080,
    ];

    /**
     * Replaces DNS resolution in tests: receives a host name and returns its
     * addresses.
     *
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $resolver = null;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The scheme, host and port of $url when the scheme is http(s), the host
     * is present and the port is allowed; null otherwise.
     *
     * @return array{scheme: string, host: string, port: int}|null
     */
    public static function parse(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || in_array($port, self::BAD_PORTS, true)) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * The addresses $url may be called at: every address its host resolves
     * to, or an empty list when the URL is not allowed, the host does not
     * resolve, or any of its addresses is refused.
     *
     * @return list<string>
     */
    public static function safeAddresses(string $url, bool $publicOnly): array
    {
        $parts = self::parse($url);

        if ($parts === null || self::isLocalhostName($parts['host'])) {
            return [];
        }

        $addresses = self::addressesOf($parts['host']);

        foreach ($addresses as $address) {
            if (! self::isAllowedAddress($address, $publicOnly)) {
                return [];
            }
        }

        return $addresses;
    }

    public static function isLocalhostName(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    public static function isAllowedAddress(string $address, bool $publicOnly): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $mapped = self::embeddedIpv4($address);

        if ($mapped !== null) {
            return self::isAllowedAddress($mapped, $publicOnly);
        }

        if (self::isAlwaysRefused($address)) {
            return false;
        }

        return ! $publicOnly
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Loopback, link-local, unspecified and multicast addresses (Redmine's
     * `valid_ips`, which also refuses these for every hook).
     */
    private static function isAlwaysRefused(string $address): bool
    {
        $binary = inet_pton($address);

        if ($binary === false) {
            return true;
        }

        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            $second = ord($binary[1]);

            return $first === 0 || $first === 127
                || ($first === 169 && $second === 254)
                || ($first >= 224 && $first <= 239);
        }

        $first = ord($binary[0]);
        $second = ord($binary[1]);

        return $binary === str_repeat("\0", 16)
            || $binary === str_repeat("\0", 15)."\1"
            || ($first === 0xFE && ($second & 0xC0) === 0x80)
            || $first === 0xFF;
    }

    /**
     * The IPv4 address inside an IPv4-mapped IPv6 address (::ffff:a.b.c.d).
     */
    private static function embeddedIpv4(string $address): ?string
    {
        $binary = inet_pton($address);

        if ($binary === false || strlen($binary) !== 16 || ! str_starts_with($binary, str_repeat("\0", 10)."\xFF\xFF")) {
            return null;
        }

        $ipv4 = inet_ntop(substr($binary, 12));

        return $ipv4 === false ? null : $ipv4;
    }

    /**
     * The A and AAAA addresses of $host (the host itself when it is an IP).
     *
     * @return list<string>
     */
    public static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
