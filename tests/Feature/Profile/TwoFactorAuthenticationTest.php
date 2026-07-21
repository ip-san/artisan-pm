<?php

use App\Models\User;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

function validTwoFactorCodeFor(User $user): string
{
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

    return app(Google2FA::class)->getCurrentOtp($secret);
}

function withRecentlyConfirmedPassword(): void
{
    session(['auth.password_confirmed_at' => now()->unix()]);
}

test('enabling two factor authentication generates a pending secret and recovery codes', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPassword();

    Livewire::actingAs($user)->test('profile.index')->call('enableTwoFactor');

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_recovery_codes)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('confirming with a valid code enables two factor authentication', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPassword();

    $component = Livewire::actingAs($user)->test('profile.index')->call('enableTwoFactor');
    $code = validTwoFactorCodeFor($user->fresh());

    $component->set('code', $code)->call('confirmTwoFactor')->assertHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('confirming with an invalid code does not enable two factor authentication', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPassword();

    Livewire::actingAs($user)->test('profile.index')
        ->call('enableTwoFactor')
        ->set('code', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors(['code']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('a user can disable an already-confirmed two factor authentication', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPassword();

    $component = Livewire::actingAs($user)->test('profile.index')->call('enableTwoFactor');
    $component->set('code', validTwoFactorCodeFor($user->fresh()))->call('confirmTwoFactor');

    $component->call('disableTwoFactor');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($user->fresh()->two_factor_secret)->toBeNull();
});

test('regenerating recovery codes replaces the stored set', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPassword();

    $component = Livewire::actingAs($user)->test('profile.index')->call('enableTwoFactor');
    $original = $user->fresh()->two_factor_recovery_codes;

    $component->call('regenerateRecoveryCodes');

    expect($user->fresh()->two_factor_recovery_codes)->not->toBe($original);
});

test('two factor management redirects to password confirmation without a recent confirmation', function () {
    $user = User::factory()->create();
    // No withRecentlyConfirmedPassword() call — session is unconfirmed.

    Livewire::actingAs($user)->test('profile.index')
        ->call('enableTwoFactor')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a user with confirmed two factor authentication is challenged on login', function () {
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create([
        'password' => 'my-password',
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'my-password']);

    $response->assertRedirect(route('two-factor.login'));
    expect(auth()->check())->toBeFalse();

    $challenge = $this->get(route('two-factor.login'));
    $challenge->assertOk();

    $completed = $this->post(route('two-factor.login'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);

    $completed->assertRedirect();
    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});
