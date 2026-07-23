<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

test('is_admin cannot be set through plain mass assignment', function () {
    $user = User::create([
        'name' => 'Mass Assignment Attempt',
        'email' => 'mass-assign@example.com',
        'password' => 'irrelevant-password',
        'is_admin' => true,
    ]);

    // is_admin is excluded from Fillable, so fill() silently discards it —
    // the in-memory model never actually had it set, so the DB's own
    // column default (false) is what the persisted row holds. Read that
    // back explicitly rather than trusting the unrefreshed in-memory value.
    expect($user->fresh()->is_admin)->toBeFalse();
});

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

test('an admin can approve a user pending self-registration', function () {
    $admin = User::factory()->admin()->create();
    $pending = User::factory()->create(['status' => UserStatus::Registered->value]);

    Livewire::actingAs($admin)->test('users.index')->call('approve', $pending->id);

    expect($pending->fresh()->status)->toBe(UserStatus::Active);
});

test('approving an already-active user is rejected', function () {
    $admin = User::factory()->admin()->create();
    $active = User::factory()->create(['status' => UserStatus::Active->value]);

    Livewire::actingAs($admin)->test('users.index')->call('approve', $active->id)->assertStatus(403);
});

test('an admin can send a password reset email to a local user', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->call('sendPasswordReset');

    Notification::assertSentTo($target, ResetPassword::class);
});

test('a non-admin cannot trigger a password reset for another user', function () {
    Notification::fake();

    $user = User::factory()->create();
    $target = User::factory()->create();

    Livewire::actingAs($user)
        ->test('users.form', ['user' => $target])
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('sending a password reset for an LDAP-linked user is rejected', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $authSource = AuthSource::factory()->create();
    $target = User::factory()->create(['auth_source_id' => $authSource->id]);

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->call('sendPasswordReset')
        ->assertStatus(400);

    Notification::assertNothingSent();
});

test('an admin can force-disable a user\'s two factor authentication', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ]);

    expect($target->hasEnabledTwoFactorAuthentication())->toBeTrue();

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $target])
        ->call('disableTwoFactor');

    expect($target->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($target->fresh()->two_factor_secret)->toBeNull();
});

test('the disable two factor button only appears for a user with it enabled', function () {
    $admin = User::factory()->admin()->create();
    $withoutTwoFactor = User::factory()->create();
    $withTwoFactor = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $withoutTwoFactor])
        ->assertDontSee('二要素認証を無効にする');

    Livewire::actingAs($admin)
        ->test('users.form', ['user' => $withTwoFactor])
        ->assertSee('二要素認証を無効にする');
});

test('a non-admin cannot force-disable another user\'s two factor authentication', function () {
    $user = User::factory()->create();
    $target = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('users.form', ['user' => $target])
        ->assertForbidden();

    expect($target->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
