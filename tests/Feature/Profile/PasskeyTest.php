<?php

use App\Models\User;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

function passkeyPasswordConfirmed(): void
{
    session(['auth.password_confirmed_at' => now()->unix()]);
}

function registeredPasskey(User $user, string $name = 'MacBook'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => 'credential-'.fake()->unique()->uuid(),
        'credential' => ['type' => 'public-key'],
    ]);
}

test('a user is a passkey user, which the package endpoints require', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(PasskeyUser::class)
        ->and($user->hasPasskeysEnabled())->toBeFalse()
        ->and($user->getPasskeyDisplayName())->toBe($user->name);

    registeredPasskey($user);

    expect($user->fresh()->hasPasskeysEnabled())->toBeTrue();
});

test('a signed-in user who confirmed their password gets registration and confirmation options', function () {
    $user = User::factory()->create();
    passkeyPasswordConfirmed();

    $this->actingAs($user)->getJson(route('passkey.registration-options'))->assertOk()
        ->assertJsonStructure(['options' => ['challenge', 'rp', 'user' => ['id', 'name', 'displayName']]]);
    // This answered 500 ("User model must implement the PasskeyUser contract") before.
    $this->actingAs($user)->getJson(route('passkey.confirm-options'))->assertOk()
        ->assertJsonStructure(['options' => ['challenge']]);
});

test('registering a passkey needs a recently confirmed password', function () {
    $this->actingAs(User::factory()->create())->getJson(route('passkey.registration-options'))->assertStatus(423);
});

test('a visitor gets sign-in options and the sign-in page offers passkeys', function () {
    $this->getJson(route('passkey.login-options'))->assertOk()->assertJsonStructure(['options' => ['challenge']]);
    $this->get(route('login'))->assertOk()->assertSee('data-passkey-login', false)->assertSee('パスキーでログイン');
});

test('the profile lists passkeys and starts a registration in the browser once the password is confirmed', function () {
    $user = User::factory()->create();
    registeredPasskey($user, '仕事用のMacBook');
    passkeyPasswordConfirmed();

    Livewire::actingAs($user)->test('profile.index')
        ->assertSee('仕事用のMacBook')
        ->set('passkeyName', 'iPhone')
        ->call('startPasskeyRegistration')
        ->assertDispatched('passkey-register', name: 'iPhone');
});

test('deleting a passkey needs a confirmed password and removes only that passkey', function () {
    $user = User::factory()->create();
    $mine = registeredPasskey($user, 'MacBook');
    $other = registeredPasskey(User::factory()->create(), 'Other');

    Livewire::actingAs($user)->test('profile.index')->call('deletePasskey', $mine->id)->assertRedirect(route('password.confirm'));
    expect(Passkey::query()->whereKey($mine->id)->exists())->toBeTrue();

    passkeyPasswordConfirmed();
    Livewire::actingAs($user)->test('profile.index')->call('deletePasskey', $other->id)->assertNotFound();
    Livewire::actingAs($user)->test('profile.index')->call('deletePasskey', $mine->id)->assertHasNoErrors();

    expect(Passkey::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(Passkey::query()->whereKey($other->id)->exists())->toBeTrue();
});
