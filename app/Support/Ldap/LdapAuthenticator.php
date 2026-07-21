<?php

declare(strict_types=1);

namespace App\Support\Ldap;

use App\Models\AuthSource;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\LdapRecordException;
use LdapRecord\Query\EscapedValue;

/**
 * Verifies a login against an LDAP directory, supporting both modes
 * Redmine's AuthSourceLdap does: search-then-bind (a service account
 * searches for the user's DN, then rebinds as it to verify the password)
 * when the AuthSource has account/account_password configured, or direct
 * bind (the submitted login builds the user's DN directly) when it doesn't.
 */
final class LdapAuthenticator
{
    /**
     * @return array{name: ?string, mail: ?string}|null the directory's name/mail attributes on success, null on any failure
     */
    public function attempt(AuthSource $source, string $login, string $password): ?array
    {
        $connection = $this->resolveConnection($source);

        try {
            $connection->connect();
        } catch (LdapRecordException) {
            return null;
        }

        return $source->usesSearchThenBind()
            ? $this->searchThenBind($connection, $source, $login, $password)
            : $this->directBind($connection, $source, $login, $password);
    }

    /**
     * Registered into LdapRecord's own Container (rather than constructed
     * fresh each call) so that LdapRecord\Laravel\Testing\DirectoryEmulator
     * — which fakes directories by pre-registering a fake connection under
     * a given name in this same Container — can intercept it in tests.
     */
    private function resolveConnection(AuthSource $source): Connection
    {
        $name = $this->connectionName($source);
        $manager = Container::getInstance()->getConnectionManager();

        if (! $manager->hasConnection($name)) {
            Container::addConnection(new Connection([
                'hosts' => [$source->host],
                'port' => $source->port,
                'base_dn' => $source->base_dn,
                'use_tls' => $source->use_tls,
                'timeout' => $source->timeout,
                'username' => $source->account,
                'password' => $source->account_password,
            ]), $name);
        }

        return Container::getConnection($name);
    }

    private function connectionName(AuthSource $source): string
    {
        return "auth-source-{$source->id}";
    }

    /**
     * @return array{name: ?string, mail: ?string}|null
     */
    private function searchThenBind(Connection $connection, AuthSource $source, string $login, string $password): ?array
    {
        $entry = $connection->query()->where($source->attr_login, '=', $login)->first();

        if ($entry === null) {
            return null;
        }

        $dn = $this->extractDn($entry);

        if ($dn === null || ! $connection->auth()->attempt($dn, $password)) {
            return null;
        }

        return $this->extractAttributes($entry, $source);
    }

    /**
     * @return array{name: ?string, mail: ?string}|null
     */
    private function directBind(Connection $connection, AuthSource $source, string $login, string $password): ?array
    {
        $dn = "{$source->attr_login}=".(new EscapedValue($login))->forDn().",{$source->base_dn}";

        if (! $connection->auth()->attempt($dn, $password, stayBound: true)) {
            return null;
        }

        $entry = $connection->query()->where($source->attr_login, '=', $login)->first();

        return $entry ? $this->extractAttributes($entry, $source) : null;
    }

    /**
     * Raw (non-Model) query results carry LDAP's native ldap_get_entries()
     * shape, where 'dn' is normally a plain string — but is defended
     * against here the same way LdapRecord's own Model hydration does,
     * since some result sources represent it as a single-item array.
     *
     * @param  array<string, mixed>  $entry
     */
    private function extractDn(array $entry): ?string
    {
        if (! array_key_exists('dn', $entry)) {
            return null;
        }

        return is_array($entry['dn']) ? ($entry['dn'][0] ?? null) : $entry['dn'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: ?string, mail: ?string}
     */
    private function extractAttributes(array $entry, AuthSource $source): array
    {
        return [
            'name' => $entry[$source->attr_name][0] ?? null,
            'mail' => $entry[$source->attr_mail][0] ?? null,
        ];
    }
}
