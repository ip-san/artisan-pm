<?php

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ConfirmAccountRegistration;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('automatic (the default) registers and activates a user immediately', function () {
    $this->post(route('register'), [
        'name' => 'New User',
        'login' => 'new-user',
        'email' => 'new-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $user = User::where('email', 'new-user@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Active)
        ->and(auth()->check())->toBeTrue();
});

test('a newly self-registered user is seeded from the default_users_no_self_notified setting', function () {
    Setting::set('default_users_no_self_notified', false);

    $this->post(route('register'), [
        'name' => 'New User',
        'login' => 'new-user',
        'email' => 'new-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $user = User::where('email', 'new-user@example.com')->firstOrFail();
    expect($user->no_self_notified)->toBeFalse();
});

test('manual mode registers the user locked, pending admin approval', function () {
    Setting::set('self_registration', 'manual');

    $this->post(route('register'), [
        'name' => 'Pending User',
        'login' => 'pending-user',
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

test('manual mode does not leave the registering request itself authenticated', function () {
    // Regression test: Fortify's RegisteredUserController unconditionally
    // calls $guard->login($user) right after creation, regardless of the
    // account's status — so without App\Http\Responses\RegisterResponse
    // overriding the default response, a pending-approval user got a live
    // authenticated session for the rest of this very request/session,
    // even though a *separate* subsequent login attempt would correctly
    // be rejected by AuthenticateUser's isActive() check. Asserting only
    // the final $user->status (as the sibling test above does) can't
    // catch this — the bug is entirely about session state, not the
    // stored row.
    Setting::set('self_registration', 'manual');

    $this->post(route('register'), [
        'name' => 'Pending User',
        'login' => 'pending-user',
        'email' => 'pending-user@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

test('email mode registers the user locked and sends an activation link, without an authenticated session', function () {
    Notification::fake();
    Setting::set('self_registration', 'email');

    $this->post(route('register'), [
        'name' => 'Awaiting Confirmation',
        'login' => 'awaiting',
        'email' => 'awaiting@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('login'));

    $user = User::where('email', 'awaiting@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Registered)
        ->and(auth()->check())->toBeFalse();

    Notification::assertSentTo($user, ConfirmAccountRegistration::class);
});

test('visiting the signed activation link activates the account and lets them log in', function () {
    Setting::set('self_registration', 'email');
    $user = User::factory()->create(['status' => UserStatus::Registered->value, 'password' => bcrypt('password')]);

    $url = URL::temporarySignedRoute('account.activate', now()->addDay(), ['user' => $user->id]);

    $this->get($url)->assertRedirect(route('login'));

    expect($user->fresh()->status)->toBe(UserStatus::Active);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    expect(auth()->check())->toBeTrue();
});

test('an expired or tampered activation link is rejected and does not activate the account', function () {
    $user = User::factory()->create(['status' => UserStatus::Registered->value]);

    $this->get(route('account.activate', ['user' => $user->id]))->assertForbidden();

    expect($user->fresh()->status)->toBe(UserStatus::Registered);
});

test('an already-active user visiting a (still validly signed) activation link gets a 404 rather than a silent no-op', function () {
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $url = URL::temporarySignedRoute('account.activate', now()->addDay(), ['user' => $user->id]);

    $this->get($url)->assertNotFound();
});

test('an activation link cannot be replayed to reactivate an account an admin has since locked', function () {
    // The signature only proves the URL wasn't tampered with and hasn't
    // expired — it says nothing about the account's *current* state. A
    // leaked 24-hour-valid link must not be able to undo an admin lock
    // applied after the account was already activated through it once.
    $user = User::factory()->create(['status' => UserStatus::Registered->value]);
    $url = URL::temporarySignedRoute('account.activate', now()->addDay(), ['user' => $user->id]);

    $this->get($url)->assertRedirect(route('login'));
    expect($user->fresh()->status)->toBe(UserStatus::Active);

    $user->update(['status' => UserStatus::Locked->value]);
    $this->get($url)->assertNotFound();

    expect($user->fresh()->status)->toBe(UserStatus::Locked);
});

test('disabled mode rejects a direct registration submission', function () {
    Setting::set('self_registration', 'disabled');

    $this->post(route('register'), [
        'name' => 'Blocked User',
        'login' => 'blocked-user',
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
        'login' => 'denied-domain-user',
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
        'login' => 'denied-subdomain-user',
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
        'login' => 'not-allowed-user',
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
        'login' => 'allowed-user',
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
        'login' => 'denied-over-allowed-user',
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
        'login' => 'case-insensitive-user',
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
        'login' => 'unrestricted-user',
        'email' => 'someone@anywhere.example',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'someone@anywhere.example')->exists())->toBeTrue();
});
