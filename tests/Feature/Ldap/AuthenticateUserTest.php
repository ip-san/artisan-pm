<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\User;
use Illuminate\Http\Request;
use LdapRecord\Laravel\Testing\DirectoryEmulator;

function loginRequest(string $email, string $password): Request
{
    return Request::create('/login', 'POST', ['email' => $email, 'password' => $password]);
}

afterEach(function () {
    DirectoryEmulator::tearDown();
});

test('a local password account still authenticates normally', function () {
    $user = User::factory()->create(['email' => 'local@example.com', 'password' => 'correct-password']);

    $result = app(AuthenticateUser::class)(loginRequest('local@example.com', 'correct-password'));

    expect($result?->is($user))->toBeTrue();
});

test('a local password account rejects the wrong password', function () {
    User::factory()->create(['email' => 'local@example.com', 'password' => 'correct-password']);

    $result = app(AuthenticateUser::class)(loginRequest('local@example.com', 'wrong-password'));

    expect($result)->toBeNull();
});

test('an unknown login with an on-the-fly LDAP source provisions a new local account', function () {
    $source = AuthSource::factory()->onTheFly()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=newuser,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['newuser'], 'cn' => ['New User'], 'mail' => ['newuser@example.com']]);
    $fake->actingAs($dn);

    $result = app(AuthenticateUser::class)(loginRequest('newuser', 'whatever-password'));

    expect($result)->not->toBeNull()
        ->and($result->email)->toBe('newuser@example.com')
        ->and($result->name)->toBe('New User')
        ->and($result->login)->toBe('newuser')
        ->and($result->auth_source_id)->toBe($source->id);
});

test('an LDAP-provisioned user reauthenticates on a later login by their stored login, not their email', function () {
    $source = AuthSource::factory()->onTheFly()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=newuser,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['newuser'], 'cn' => ['New User'], 'mail' => ['newuser@example.com']]);
    $fake->actingAs($dn);

    $first = app(AuthenticateUser::class)(loginRequest('newuser', 'whatever-password'));
    $second = app(AuthenticateUser::class)(loginRequest('newuser', 'whatever-password'));

    expect($second?->is($first))->toBeTrue()
        ->and(User::where('email', 'newuser@example.com')->count())->toBe(1);
});

test('a login matching no local account and no accepting LDAP source returns null', function () {
    AuthSource::factory()->onTheFly()->create();

    $result = app(AuthenticateUser::class)(loginRequest('nobody@example.com', 'whatever-password'));

    expect($result)->toBeNull();
});

test('an LDAP source not enabled for on-the-fly registration is not tried for an unknown login', function () {
    $source = AuthSource::factory()->create(['onthefly_register' => false, 'attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=someone,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['someone'], 'cn' => ['Someone'], 'mail' => ['someone@example.com']]);
    $fake->actingAs($dn);

    $result = app(AuthenticateUser::class)(loginRequest('someone', 'whatever-password'));

    expect($result)->toBeNull()
        ->and(User::count())->toBe(0);
});

test('a locked local account cannot authenticate even with the correct password', function () {
    User::factory()->create(['email' => 'locked@example.com', 'password' => 'correct-password', 'status' => UserStatus::Locked->value]);

    $result = app(AuthenticateUser::class)(loginRequest('locked@example.com', 'correct-password'));

    expect($result)->toBeNull();
});

test('a locked LDAP-linked account cannot authenticate even when the directory accepts it', function () {
    $source = AuthSource::factory()->create(['attr_login' => 'uid', 'base_dn' => 'dc=example,dc=com']);
    $fake = fakeAuthSourceDirectory($source);

    $dn = 'uid=jdoe,dc=example,dc=com';
    $fake->query()->insert($dn, ['objectclass' => ['inetOrgPerson'], 'uid' => ['jdoe'], 'cn' => ['John Doe'], 'mail' => ['jdoe@example.com']]);
    $fake->actingAs($dn);
    User::factory()->create(['auth_source_id' => $source->id, 'login' => 'jdoe', 'email' => 'jdoe@example.com', 'status' => UserStatus::Locked->value]);

    $result = app(AuthenticateUser::class)(loginRequest('jdoe', 'whatever-password'));

    expect($result)->toBeNull();
});
