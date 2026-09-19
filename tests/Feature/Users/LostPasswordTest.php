<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('the forgot password page renders and the login page links to it', function () {
    $this->get(route('password.request'))->assertOk()->assertSee('再設定リンクを送信');
    $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false);
});

test('a user can request a reset link and use it to set a new password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'lost@example.com']);

    $this->post(route('password.email'), ['email' => 'lost@example.com'])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->get(route('password.reset', ['token' => $token, 'email' => 'lost@example.com']))
        ->assertOk()
        ->assertSee('name="token" value="'.$token.'"', false);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'lost@example.com',
        'password' => 'a-new-Password-123',
        'password_confirmation' => 'a-new-Password-123',
    ])->assertSessionHasNoErrors();

    expect(password_verify('a-new-Password-123', $user->fresh()->password))->toBeTrue();
});

test('with lost_password off the request endpoints redirect to login and no mail is sent', function () {
    Notification::fake();
    Setting::set('lost_password', false);
    User::factory()->create(['email' => 'lost@example.com']);

    $this->get(route('password.request'))->assertRedirect(route('login'));
    $this->post(route('password.email'), ['email' => 'lost@example.com'])->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk()->assertDontSee(route('password.request'), false);

    Notification::assertNothingSent();
});

test('with lost_password off an administrator issued link still works', function () {
    Setting::set('lost_password', false);
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-Password-123',
        'password_confirmation' => 'a-new-Password-123',
    ])->assertSessionHasNoErrors();

    expect(password_verify('a-new-Password-123', $user->fresh()->password))->toBeTrue();
});

test('no reset mail is sent to a locked or externally authenticated user, and the request still succeeds', function () {
    Notification::fake();
    $locked = User::factory()->create(['status' => UserStatus::Locked]);
    $ldap = User::factory()->create(['auth_source_id' => AuthSource::factory()->create()->id]);

    foreach ([$locked->email, $ldap->email] as $email) {
        $this->post(route('password.email'), ['email' => $email])->assertSessionHasNoErrors();
    }

    Notification::assertNothingSent();
});

test('a token issued before the account was locked cannot reset the password', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $user->update(['status' => UserStatus::Locked]);
    $original = $user->fresh()->password;

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-Password-123',
        'password_confirmation' => 'a-new-Password-123',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBe($original);
});

test('the settings page saves lost_password', function () {
    $admin = User::factory()->admin()->create();

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('lost_password', true)
        ->set('lost_password', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('lost_password', true))->toBeFalse();
});
