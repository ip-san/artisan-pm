<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('an admin can create a local user with a password', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'New User')
        ->set('email', 'new-user@example.com')
        ->set('password', 'a-strong-password')
        ->set('password_confirmation', 'a-strong-password')
        ->call('save')
        ->assertRedirect(route('users.index'));

    $user = User::where('email', 'new-user@example.com')->firstOrFail();

    expect(Hash::check('a-strong-password', $user->password))->toBeTrue()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->is_admin)->toBeFalse();
});

test('creating a local user without a password fails validation', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'No Password')
        ->set('email', 'no-password@example.com')
        ->call('save')
        ->assertHasErrors(['password']);
});

test('an admin can create an LDAP-linked user without a password', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'LDAP User')
        ->set('email', 'ldap-user@example.com')
        ->set('auth_source_id', $source->id)
        ->set('login', 'ldapuser')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'ldap-user@example.com')->firstOrFail();

    expect($user->auth_source_id)->toBe($source->id)
        ->and($user->login)->toBe('ldapuser');
});

test('an LDAP-linked user requires a login', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'LDAP User')
        ->set('email', 'ldap-user@example.com')
        ->set('auth_source_id', $source->id)
        ->call('save')
        ->assertHasErrors(['login']);
});

test('an admin can edit a user and grant is_admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->set('name', 'Updated Name')
        ->set('is_admin', true)
        ->call('save');

    expect($target->fresh()->name)->toBe('Updated Name')
        ->and($target->fresh()->is_admin)->toBeTrue();
});

test('leaving the password blank on edit keeps the existing password', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['password' => 'original-password']);
    $originalHash = $target->password;

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->set('name', 'Renamed')
        ->call('save');

    expect($target->fresh()->password)->toBe($originalHash);
});

test('submitting a new password on edit updates it', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->call('save');

    expect(Hash::check('brand-new-password', $target->fresh()->password))->toBeTrue();
});

test('a non-admin cannot access user administration', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Livewire::actingAs($user)->test('users.index')->assertForbidden();
    Livewire::actingAs($user)->test('users.form')->assertForbidden();
    Livewire::actingAs($user)->test('users.form', ['user' => $other])->assertForbidden();
});

test('an admin can lock another user, blocking their login', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['password' => 'correct-password']);

    Livewire::actingAs($admin)->test('users.index')->call('toggleLock', $target->id);

    expect($target->fresh()->status)->toBe(UserStatus::Locked);
});

test('an admin can unlock a locked user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Locked->value]);

    Livewire::actingAs($admin)->test('users.index')->call('toggleLock', $target->id);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

test('an admin cannot lock their own account from the user list', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('users.index')->call('toggleLock', $admin->id)->assertForbidden();

    expect($admin->fresh()->status)->toBe(UserStatus::Active);
});
