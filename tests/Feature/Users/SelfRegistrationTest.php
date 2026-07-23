<?php

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;

test('automatic (the default) registers and activates a user immediately', function () {
    $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $user = User::where('email', 'new-user@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Active)
        ->and(auth()->check())->toBeTrue();
});

test('manual mode registers the user locked, pending admin approval', function () {
    Setting::set('self_registration', 'manual');

    $this->post(route('register'), [
        'name' => 'Pending User',
        'email' => 'pending-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $user = User::where('email', 'pending-user@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Registered);
});

test('a manually-registered user cannot log in until approved', function () {
    Setting::set('self_registration', 'manual');
    $user = User::factory()->create(['status' => UserStatus::Registered->value, 'password' => bcrypt('password')]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    expect(auth()->check())->toBeFalse();
});

test('disabled mode rejects a direct registration submission', function () {
    Setting::set('self_registration', 'disabled');

    $this->post(route('register'), [
        'name' => 'Blocked User',
        'email' => 'blocked-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors();

    expect(User::where('email', 'blocked-user@example.com')->exists())->toBeFalse();
});

test('disabled mode redirects the registration page to login', function () {
    Setting::set('self_registration', 'disabled');

    $this->get(route('register'))->assertRedirect(route('login'));
});

test('automatic mode shows the registration form', function () {
    $this->get(route('register'))->assertOk();
});

test('a denied email domain is rejected even when no allow list is configured', function () {
    Setting::set('email_domains_denied', 'blocked.example');

    $this->post(route('register'), [
        'name' => 'Denied Domain User',
        'email' => 'someone@blocked.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'someone@blocked.example')->exists())->toBeFalse();
});

test('a denied subdomain wildcard (leading dot) rejects any matching subdomain', function () {
    Setting::set('email_domains_denied', '.blocked.example');

    $this->post(route('register'), [
        'name' => 'Denied Subdomain User',
        'email' => 'someone@mail.blocked.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'someone@mail.blocked.example')->exists())->toBeFalse();
});

test('when an allow list is configured, a domain outside it is rejected', function () {
    Setting::set('email_domains_allowed', 'allowed.example');

    $this->post(route('register'), [
        'name' => 'Not Allowed User',
        'email' => 'someone@other.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'someone@other.example')->exists())->toBeFalse();
});

test('when an allow list is configured, a matching domain is accepted', function () {
    Setting::set('email_domains_allowed', 'allowed.example');

    $this->post(route('register'), [
        'name' => 'Allowed User',
        'email' => 'someone@allowed.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'someone@allowed.example')->exists())->toBeTrue();
});

test('a denied domain is rejected even if it also matches the allow list', function () {
    Setting::set('email_domains_allowed', 'example.com');
    Setting::set('email_domains_denied', 'blocked.example.com');

    $this->post(route('register'), [
        'name' => 'Denied Over Allowed User',
        'email' => 'someone@blocked.example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'someone@blocked.example.com')->exists())->toBeFalse();
});

test('domain matching is case-insensitive', function () {
    Setting::set('email_domains_allowed', 'Example.COM');

    $this->post(route('register'), [
        'name' => 'Case Insensitive User',
        'email' => 'someone@EXAMPLE.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    // The app itself normalizes the stored email to lowercase, independent
    // of this feature's own case-insensitive domain matching.
    expect(User::where('email', 'someone@example.com')->exists())->toBeTrue();
});

test('with no domain restrictions configured, any domain is accepted', function () {
    $this->post(route('register'), [
        'name' => 'Unrestricted User',
        'email' => 'someone@anywhere.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'someone@anywhere.example')->exists())->toBeTrue();
});
