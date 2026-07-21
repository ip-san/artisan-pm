<?php

use App\Models\AuthSource;
use App\Support\Ldap\LdapAuthenticator;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Laravel\Testing\EmulatedConnectionFake;

/**
 * DirectoryEmulator::setup() replaces an already-registered connection with
 * a fake one, so a stub must be registered under the AuthSource's
 * connection name first — mirroring what LdapAuthenticator itself would
 * register lazily in production on its first real use.
 */
function fakeAuthSourceDirectory(AuthSource $source): EmulatedConnectionFake
{
    $name = "auth-source-{$source->id}";

    Container::addConnection(new Connection(['base_dn' => $source->base_dn]), $name);

    return DirectoryEmulator::setup($name);
}

afterEach(function () {
    DirectoryEmulator::tearDown();
});

test('search-then-bind succeeds and returns the directory attributes', function () {
    $source = AuthSource::factory()->searchThenBind()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=jdoe,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['jdoe'], 'cn' => ['John Doe'], 'mail' => ['jdoe@example.com']]);
    $fake->actingAs($dn);

    $result = app(LdapAuthenticator::class)->attempt($source, 'jdoe', 'whatever-password');

    expect($result)->toBe(['name' => 'John Doe', 'mail' => 'jdoe@example.com']);
});

test('search-then-bind fails when no directory entry matches the login', function () {
    $source = AuthSource::factory()->searchThenBind()->create();
    fakeAuthSourceDirectory($source);

    $result = app(LdapAuthenticator::class)->attempt($source, 'nobody', 'whatever-password');

    expect($result)->toBeNull();
});

test('search-then-bind fails when the directory rejects the rebind', function () {
    $source = AuthSource::factory()->searchThenBind()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=jdoe,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['jdoe'], 'cn' => ['John Doe'], 'mail' => ['jdoe@example.com']]);
    // No actingAs() call — nothing authorizes this DN to bind.

    $result = app(LdapAuthenticator::class)->attempt($source, 'jdoe', 'wrong-password');

    expect($result)->toBeNull();
});

test('direct bind succeeds by constructing the DN from the login', function () {
    $source = AuthSource::factory()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com', 'account' => null, 'account_password' => null]);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=jdoe,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['jdoe'], 'cn' => ['John Doe'], 'mail' => ['jdoe@example.com']]);
    $fake->actingAs($dn);

    $result = app(LdapAuthenticator::class)->attempt($source, 'jdoe', 'whatever-password');

    expect($result)->toBe(['name' => 'John Doe', 'mail' => 'jdoe@example.com']);
});

test('direct bind fails when the constructed DN is not authorized to bind', function () {
    $source = AuthSource::factory()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com', 'account' => null, 'account_password' => null]);
    fakeAuthSourceDirectory($source);

    $result = app(LdapAuthenticator::class)->attempt($source, 'jdoe', 'wrong-password');

    expect($result)->toBeNull();
});
