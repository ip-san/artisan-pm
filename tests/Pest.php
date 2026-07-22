<?php

use App\Models\AuthSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Laravel\Testing\EmulatedConnectionFake;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Builds an expected CSV row the same way fputcsv() would (quoting rules
 * vary by PHP version), rather than hand-writing a literal string that
 * could silently drift from actual fputcsv() behavior.
 *
 * @param  array<int, string>  $fields
 */
function csvRow(array $fields, string $separator = ','): string
{
    $handle = fopen('php://memory', 'w+');
    fputcsv($handle, $fields, $separator);
    rewind($handle);
    $row = stream_get_contents($handle);
    fclose($handle);

    return $row;
}

/**
 * Fakes the LDAP directory behind an AuthSource for testing.
 * DirectoryEmulator::setup() replaces an already-registered connection
 * with a fake one, so a stub must be registered under the AuthSource's
 * connection name first — mirroring what LdapAuthenticator itself would
 * register lazily in production on its first real use. Pair with
 * DirectoryEmulator::tearDown() in afterEach().
 */
function fakeAuthSourceDirectory(AuthSource $source): EmulatedConnectionFake
{
    $name = "auth-source-{$source->id}";

    Container::addConnection(new Connection(['base_dn' => $source->base_dn]), $name);

    return DirectoryEmulator::setup($name);
}
