<?php

use App\Models\Setting;
use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('the remember-me checkbox is hidden on the login page by default', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('name="remember"', escape: false);
});

test('the remember-me checkbox appears once the autologin setting is enabled', function () {
    Setting::set('autologin', true);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('name="remember"', escape: false);
});

test('posting remember=1 while autologin is disabled does not set a recaller cookie', function () {
    $user = User::factory()->create(['password' => 'correct-password']);

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'remember' => '1',
    ]);

    $response->assertRedirect();
    expect(auth()->check())->toBeTrue();
    $response->assertCookieMissing(auth()->guard('web')->getRecallerName());
});

test('posting remember=1 while autologin is enabled sets a recaller cookie', function () {
    Setting::set('autologin', true);
    $user = User::factory()->create(['password' => 'correct-password']);

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'remember' => '1',
    ]);

    $response->assertRedirect();
    expect(auth()->check())->toBeTrue();
    $response->assertCookie(auth()->guard('web')->getRecallerName());
});

test('remember=1 does not survive the 2FA challenge when autologin is disabled', function () {
    // Fortify doesn't read "remember" fresh at the challenge step — it
    // stashes login.remember into the session during login.store
    // (RedirectIfTwoFactorAuthenticatable) and the challenge controller
    // pulls it back out later. Stripping the input at login.store, before
    // that stash happens, closes this path too — but it's a second,
    // separate route from the plain-password login the other tests here
    // cover, so it needs its own end-to-end check.
    // Confirms via a direct column update rather than
    // ConfirmTwoFactorAuthentication (which consumes an OTP): Fortify's
    // TwoFactorAuthenticationProvider rejects reusing that same code
    // again within its replay-protection window, and the login challenge
    // below needs a code of its own — bypassing the setup step's OTP
    // avoids that collision entirely rather than working around timing.
    $user = User::factory()->create(['password' => 'correct-password']);
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'remember' => '1',
    ])->assertRedirect(route('two-factor.login'));

    expect(auth()->check())->toBeFalse();

    $response = $this->post(route('two-factor.login.store'), [
        'code' => $code,
    ]);

    $response->assertRedirect();
    expect(auth()->check())->toBeTrue();
    $response->assertCookieMissing(auth()->guard('web')->getRecallerName());
});
