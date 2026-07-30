<?php

use App\Models\AuthSource;
use App\Support\Ldap\LdapAuthenticator;
use LdapRecord\Connection;
use LdapRecord\Laravel\Testing\DirectoryEmulator;

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

test('a configured filter is ANDed into the compiled LDAP search filter', function () {
    // LdapRecord\Laravel\Testing\DirectoryEmulator (used by the other
    // tests in this file) cannot actually enforce a raw filter — its
    // Eloquent-backed query translator only understands Equals/Has/
    // Contains/group filters, and silently ignores a Raw one (verified by
    // inspecting LdapRecord\Laravel\Testing\EmulatesQueries::
    // applyFilterToEloquentQuery(), which has no branch for the Raw
    // filter class rawFilter() produces). So the only way to genuinely
    // prove the filter reaches the real LDAP query — rather than trusting
    // that "the emulator returned what I expected" — is to inspect the
    // actual compiled filter string LdapAuthenticator builds, via the
    // real (unconnected) LdapRecord\Query\Builder it constructs.
    $source = AuthSource::factory()->create(['attr_login' => 'uid', 'filter' => '(memberOf=cn=staff,dc=example,dc=com)']);
    $connection = new Connection(['base_dn' => $source->base_dn]);

    $baseQuery = new ReflectionMethod(LdapAuthenticator::class, 'baseQuery');
    $baseQuery->setAccessible(true);

    $query = $baseQuery->invoke(app(LdapAuthenticator::class), $connection, $source)
        ->where($source->attr_login, '=', 'jdoe');

    // getUnescapedQuery() is the pre-escape form; getQuery() is the string
    // actually handed to ldap_search(). Asserting both closes the gap
    // where the raw filter could be mangled by escaping between the two —
    // and in fact reveals real (expected) LDAP behavior: the ordinary
    // where() value ('jdoe') gets hex-escaped per RFC4515, while the
    // admin-authored raw filter clause is left untouched, since it's
    // meant to be raw LDAP syntax rather than a literal value to escape.
    expect($query->getUnescapedQuery())->toBe('(&(memberOf=cn=staff,dc=example,dc=com)(uid=jdoe))')
        ->and($query->getQuery())->toBe('(&(memberOf=cn=staff,dc=example,dc=com)(uid=\6a\64\6f\65))');
});

test('with no filter configured, the compiled LDAP search filter carries no extra AND clause', function () {
    $source = AuthSource::factory()->create(['attr_login' => 'uid', 'filter' => null]);
    $connection = new Connection(['base_dn' => $source->base_dn]);

    $baseQuery = new ReflectionMethod(LdapAuthenticator::class, 'baseQuery');
    $baseQuery->setAccessible(true);

    $query = $baseQuery->invoke(app(LdapAuthenticator::class), $connection, $source)
        ->where($source->attr_login, '=', 'jdoe');

    expect($query->getUnescapedQuery())->toBe('(uid=jdoe)');
});

test('a login still succeeds end-to-end through search-then-bind when a filter is configured', function () {
    $source = AuthSource::factory()->searchThenBind()->create([
        'attr_login' => 'uid',
        'base_dn' => 'dc=example,dc=com',
        'filter' => '(memberOf=cn=staff,dc=example,dc=com)',
    ]);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=jdoe,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['jdoe'], 'cn' => ['John Doe'], 'mail' => ['jdoe@example.com'], 'memberof' => ['cn=staff,dc=example,dc=com']]);
    $fake->actingAs($dn);

    $result = app(LdapAuthenticator::class)->attempt($source, 'jdoe', 'whatever-password');

    expect($result)->toBe(['name' => 'John Doe', 'mail' => 'jdoe@example.com']);
});
