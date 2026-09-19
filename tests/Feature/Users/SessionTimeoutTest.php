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

test('with session_lifetime disabled (the default) an old session is left alone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['session_started_at' => now()->subDays(90)->timestamp, 'last_activity_at' => now()->timestamp])
        ->get(route('projects.index'))
        ->assertOk();
});

test('a session older than session_lifetime is invalidated even while it is active', function () {
    Setting::set('session_lifetime', 480);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['session_started_at' => now()->subMinutes(481)->timestamp, 'last_activity_at' => now()->timestamp])
        ->get(route('projects.index'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

test('a session within its lifetime stays valid and gets its start stamped on first sight', function () {
    Setting::set('session_lifetime', 480);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk();

    expect(session('session_started_at'))->toBeInt()
        ->and(auth()->check())->toBeTrue();
});

test('the start stamp is not refreshed by later requests, so activity cannot extend the lifetime', function () {
    Setting::set('session_lifetime', 480);
    $user = User::factory()->create();
    $started = now()->subMinutes(100)->timestamp;

    $this->actingAs($user)
        ->withSession(['session_started_at' => $started])
        ->get(route('projects.index'))
        ->assertOk();

    expect(session('session_started_at'))->toBe($started);
});

test('either limit alone ends a session and the lifetime works alongside the idle timeout', function () {
    Setting::set('session_timeout', 60);
    Setting::set('session_lifetime', 1440);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['session_started_at' => now()->subMinutes(30)->timestamp, 'last_activity_at' => now()->subMinutes(61)->timestamp])
        ->get(route('projects.index'))
        ->assertRedirect(route('login'));
});

test('the settings page saves session_lifetime and rejects unknown values', function () {
    $admin = User::factory()->admin()->create();

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('session_lifetime', 0)
        ->set('session_lifetime', 1440)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('session_lifetime'))->toBe(1440);

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->set('session_lifetime', 7)
        ->call('save')
        ->assertHasErrors('session_lifetime');
});
