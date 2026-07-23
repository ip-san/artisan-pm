<?php

use App\Models\Setting;
use App\Models\User;

test('with session_timeout disabled (the default), a stale session is left alone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['last_activity_at' => now()->subDays(10)->timestamp])
        ->get(route('projects.index'))
        ->assertOk();

    expect(auth()->check())->toBeTrue();
});

test('a session idle for longer than session_timeout is invalidated', function () {
    Setting::set('session_timeout', 60);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['last_activity_at' => now()->subMinutes(61)->timestamp])
        ->get(route('projects.index'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

test('a session within the session_timeout window stays valid', function () {
    Setting::set('session_timeout', 60);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['last_activity_at' => now()->subMinutes(30)->timestamp])
        ->get(route('projects.index'))
        ->assertOk();

    expect(auth()->check())->toBeTrue();
});

test('a brand-new session with no recorded activity yet is not treated as expired', function () {
    Setting::set('session_timeout', 60);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk();

    expect(auth()->check())->toBeTrue();
});
