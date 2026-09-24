<?php

declare(strict_types=1);

namespace App\Support\Scm;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for remote Subversion repository URLs (A10-01b). Used both
 * by the RemoteRepositoryUrl validation rule and by SvnAdapter right
 * before every svn invocation, so a host whose DNS record changes after
 * the URL was saved (DNS rebinding) is refused at use time too.
 *
 * See config/scm.php's allowed_hosts for the allow-list format. The
 * address check is deliberately stricter than the host check: listing a
 * host name does not by itself let it resolve to a loopback, private or
 * link-local address — that address has to be listed too.
 *
 * A small window remains between this check and svn's own resolution of
 * the same name; closing it would mean pinning svn to an IP, which breaks
 * TLS/virtual hosting for http(s) URLs.
 */
final class RemoteRepositoryUrlGuard
{
    /**
     * svn+ssh is excluded on purpose: it runs a local ssh client whose
     * behavior the URL can influence, which is a far wider surface than a
     * network connection.
     */
    private const ALLOWED_SCHEMES = ['svn', 'http', 'https'];

    /**
     * Why $url may not be used, or null when it may.
     */
    public static function problem(string $url): ?string
    {
        $allowList = self::allowList();

        if ($allowList === []) {
            return __('リモートリポジトリは無効です(許可ホストが設定されていません)。');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return __('URLの形式が正しくありません。');
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return __('URLのスキームは svn://、http://、https:// のいずれかにしてください。');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return __('URLにユーザー名やパスワードを含めないでください。ログインIDとパスワードの欄を使用してください。');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (! self::hostIsListed($host, $allowList)) {
            return __('ホスト「:host」は許可されていません。', ['host' => $host]);
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            return __('ホスト「:host」の名前を解決できません。', ['host' => $host]);
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address) && ! self::addressIsListed($address, $allowList)) {
                return __('ホスト「:host」は許可されていないアドレス(:address)に解決されます。', ['host' => $host, 'address' => $address]);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function allowList(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $entry) => strtolower(trim((string) $entry)),
            (array) config('scm.allowed_hosts', []),
        )));
    }

    /**
     * @param  array<int, string>  $allowList
     */
    private static function hostIsListed(string $host, array $allowList): bool
    {
        if (in_array($host, $allowList, true)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && self::addressIsListed($host, $allowList);
    }

    /**
     * @param  array<int, string>  $allowList
     */
    private static function addressIsListed(string $address, array $allowList): bool
    {
        $ranges = array_values(array_filter(
            $allowList,
            fn (string $entry) => str_contains($entry, '/') || filter_var($entry, FILTER_VALIDATE_IP) !== false,
        ));

        return $ranges !== [] && IpUtils::checkIp($address, $ranges);
    }

    private static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * Every IPv4 and IPv6 address the host resolves to (itself, for an IP
     * literal). gethostbynamel() also consults /etc/hosts, which
     * dns_get_record() does not.
     *
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);

        foreach ($records ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
