<?php

use App\Models\AuthSource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a user can update their profile information', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('updateProfile');

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->email)->toBe('new@example.com');
});

test('a local account can change its password with the correct current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->set('current_password', 'old-password')
        ->set('password', 'a-new-strong-password')
        ->set('password_confirmation', 'a-new-strong-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('a-new-strong-password', $user->fresh()->password))->toBeTrue();
});

test('changing the password fails with the wrong current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->set('current_password', 'wrong-password')
        ->set('password', 'a-new-strong-password')
        ->set('password_confirmation', 'a-new-strong-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
});

test('an LDAP-linked account cannot change its local password', function () {
    $source = AuthSource::factory()->create();
    $user = User::factory()->create(['auth_source_id' => $source->id, 'login' => 'jdoe']);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->set('current_password', 'whatever')
        ->set('password', 'a-new-strong-password')
        ->set('password_confirmation', 'a-new-strong-password')
        ->call('updatePassword')
        ->assertForbidden();
});
