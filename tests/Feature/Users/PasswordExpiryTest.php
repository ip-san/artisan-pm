<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\AuthSource;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a user flagged to change their password is sent to the profile page everywhere else', function () {
    $user = User::factory()->create(['must_change_passwd' => true]);

    $this->actingAs($user)->get(route('projects.index'))->assertRedirect(route('profile.index'));
    $this->actingAs($user)->get(route('profile.index'))->assertOk();
});

test('an unflagged user is not redirected', function () {
    $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertOk();
});

test('an expired password forces the change once password_max_age is on', function () {
    $user = User::factory()->create();
    $user->forceFill(['passwd_changed_on' => now()->subDays(40)])->saveQuietly();

    $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk();

    Setting::set('password_max_age', 30);
    $this->actingAs($user->fresh())->get(route('projects.index'))->assertRedirect(route('profile.index'));

    Setting::set('password_max_age', 90);
    $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk();
});

test('an account whose password is kept in a directory is never forced', function () {
    Setting::set('password_max_age', 30);
    $user = User::factory()->create(['auth_source_id' => AuthSource::factory()->create()->id, 'must_change_passwd' => true]);
    $user->forceFill(['passwd_changed_on' => now()->subYears(2)])->saveQuietly();

    $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk();
});

test('changing the password on the profile page lifts the requirement', function () {
    $user = User::factory()->create(['password' => 'old-password-123', 'must_change_passwd' => true]);
    $user->forceFill(['passwd_changed_on' => now()->subDays(5)])->saveQuietly();

    Livewire::actingAs($user)->test('profile.index')
        ->set('current_password', 'old-password-123')->set('password', 'A-new-password-456')->set('password_confirmation', 'A-new-password-456')
        ->call('updatePassword')->assertHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->must_change_passwd)->toBeFalse()->and($fresh->passwd_changed_on->gt(now()->subMinute()))->toBeTrue();
    $this->actingAs($fresh)->get(route('projects.index'))->assertOk();
});

test('resetting or updating the password through Fortify also lifts it', function () {
    $user = User::factory()->create(['password' => 'old-password-123', 'must_change_passwd' => true]);

    app(ResetUserPassword::class)->reset($user, ['password' => 'A-new-password-456', 'password_confirmation' => 'A-new-password-456']);
    expect($user->fresh()->must_change_passwd)->toBeFalse();

    $user->forceFill(['must_change_passwd' => true])->save();
    $this->actingAs($user);
    app(UpdateUserPassword::class)->update($user->fresh(), ['current_password' => 'A-new-password-456', 'password' => 'Another-password-789', 'password_confirmation' => 'Another-password-789']);
    expect($user->fresh()->must_change_passwd)->toBeFalse();
});

test('the password age restarts only when the password itself changes', function () {
    $user = User::factory()->create();
    $user->forceFill(['passwd_changed_on' => now()->subDays(10)])->saveQuietly();

    $user->update(['name' => 'Renamed']);
    expect($user->fresh()->passwd_changed_on->lt(now()->subDays(9)))->toBeTrue();

    $user->update(['password' => 'brand-new-password-1']);
    expect($user->fresh()->passwd_changed_on->gt(now()->subMinute()))->toBeTrue()
        ->and(Hash::check('brand-new-password-1', $user->fresh()->password))->toBeTrue();
});

test('a new account starts its password age at creation', function () {
    expect(User::factory()->create()->fresh()->passwd_changed_on->gt(now()->subMinute()))->toBeTrue();
});

test('an administrator can require a password change from the user form', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)->test('users.form', ['user' => $target])->assertSee('次回ログイン時にパスワードの変更を要求する')
        ->set('must_change_passwd', true)->call('save')->assertHasNoErrors();
    expect($target->fresh()->must_change_passwd)->toBeTrue();

    Livewire::actingAs($admin)->test('users.form', ['user' => $target->fresh()])->assertSet('must_change_passwd', true)
        ->set('must_change_passwd', false)->call('save');
    expect($target->fresh()->must_change_passwd)->toBeFalse();
});

test('the flag is never stored for a directory-backed account', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['auth_source_id' => AuthSource::factory()->create()->id]);

    Livewire::actingAs($admin)->test('users.form', ['user' => $target])->set('must_change_passwd', true)->call('save');

    expect($target->fresh()->must_change_passwd)->toBeFalse();
});

test('the settings page saves the maximum age and rejects an odd value', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('password_max_age', 90)->call('save')->assertHasNoErrors();
    expect((int) Setting::get('password_max_age'))->toBe(90);

    Livewire::actingAs($admin)->test('settings.index')->set('password_max_age', 45)->call('save')->assertHasErrors(['password_max_age']);
});
