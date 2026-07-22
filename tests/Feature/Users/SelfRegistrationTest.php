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
