<?php

use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

test('an admin can configure the minimum password length', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('password_min_length', 12)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('password_min_length'))->toBe(12);
});

test('password_min_length must be a positive integer within range', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('password_min_length', 0)
        ->call('save')
        ->assertHasErrors(['password_min_length']);
});

test('registration rejects a password shorter than the configured minimum', function () {
    Setting::set('password_min_length', 12);

    $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'short-password@example.com',
        'password' => 'short1234',
        'password_confirmation' => 'short1234',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'short-password@example.com')->exists())->toBeFalse();
});

test('registration accepts a password meeting the configured minimum', function () {
    Setting::set('password_min_length', 12);

    $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'long-enough@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    expect(User::where('email', 'long-enough@example.com')->exists())->toBeTrue();
});

test('an admin creating a user is bound by the configured minimum password length', function () {
    Setting::set('password_min_length', 20);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'New User')
        ->set('email', 'new-user@example.com')
        ->set('password', 'too-short')
        ->set('password_confirmation', 'too-short')
        ->call('save')
        ->assertHasErrors(['password']);
});

test('a user changing their own password is bound by the configured minimum length', function () {
    Setting::set('password_min_length', 20);
    $user = User::factory()->create(['password' => 'old-password']);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->set('current_password', 'old-password')
        ->set('password', 'too-short')
        ->set('password_confirmation', 'too-short')
        ->call('updatePassword')
        ->assertHasErrors(['password']);
});

test('the default minimum of 8 applies when the setting has never been configured', function () {
    $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'default-length@example.com',
        'password' => 'short12',
        'password_confirmation' => 'short12',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'default-length@example.com')->exists())->toBeFalse();
});
