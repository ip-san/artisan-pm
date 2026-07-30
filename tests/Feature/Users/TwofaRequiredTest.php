<?php

use App\Models\Setting;
use App\Models\User;

test('with twofa disabled (the default), a user without 2FA can reach any authenticated page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk();
});

test('twofa=2 (required for everyone) redirects a user without 2FA to the profile page', function () {
    Setting::set('twofa', '2');
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertRedirect(route('profile.index'))
        ->assertSessionHas('status');

    expect(auth()->check())->toBeTrue();
});

test('twofa=2 does not redirect the profile page itself, to avoid a redirect loop', function () {
    Setting::set('twofa', '2');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.index'))
        ->assertOk();
});

test('twofa=2 still forces a user who generated a secret but never confirmed it', function () {
    Setting::set('twofa', '2');
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => null,
    ]);

    expect($user->mustActivateTwoFactor())->toBeTrue();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertRedirect(route('profile.index'));
});

test('twofa=2 does not redirect a user who already has 2FA enabled and confirmed', function () {
    Setting::set('twofa', '2');
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk();
});

test('twofa=3 (required for administrators only) redirects an admin without 2FA', function () {
    Setting::set('twofa', '3');
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('projects.index'))
        ->assertRedirect(route('profile.index'));
});

test('twofa=3 (required for administrators only) leaves a non-admin without 2FA alone', function () {
    Setting::set('twofa', '3');
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk();
});

test('twofa=1 (optional) does not force anyone in this app, since group-level twofa_required has no equivalent here', function () {
    Setting::set('twofa', '1');
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk();
});

test('User::mustActivateTwoFactor reflects the raw setting tier directly, not just the final redirect behavior', function () {
    Setting::set('twofa', '2');
    $everyone = User::factory()->create(['is_admin' => false]);
    expect($everyone->mustActivateTwoFactor())->toBeTrue();

    Setting::set('twofa', '3');
    $admin = User::factory()->create(['is_admin' => true]);
    $regular = User::factory()->create(['is_admin' => false]);
    expect($admin->mustActivateTwoFactor())->toBeTrue()
        ->and($regular->mustActivateTwoFactor())->toBeFalse();

    Setting::set('twofa', '0');
    expect($admin->mustActivateTwoFactor())->toBeFalse();
});
