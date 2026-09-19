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
        'login' => 'short-password',
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
        'login' => 'long-enough',
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
        ->set('login', 'new-user')
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
        'login' => 'default-length',
        'email' => 'default-length@example.com',
        'password' => 'short12',
        'password_confirmation' => 'short12',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'default-length@example.com')->exists())->toBeFalse();
});

function charClassPasswordInput(string $password): array
{
    return [
        'name' => 'Char Class', 'login' => 'charclass', 'email' => 'charclass@example.com',
        'password' => $password, 'password_confirmation' => $password,
    ];
}

test('an admin can require character classes and the choice is saved', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('password_required_char_classes', [])
        ->set('password_required_char_classes', ['uppercase', 'digits'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('password_required_char_classes'))->toBe(['uppercase', 'digits']);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('password_required_char_classes', ['emoji'])
        ->call('save')
        ->assertHasErrors('password_required_char_classes.0');
});

test('each required class must be present independently', function (array $required, string $password, bool $valid) {
    Setting::set('password_required_char_classes', $required);

    $validator = Illuminate\Support\Facades\Validator::make(['password' => $password], ['password' => Illuminate\Validation\Rules\Password::default()]);

    expect($validator->passes())->toBe($valid);
})->with([
    'upper only required, has upper' => [['uppercase'], 'longpasswordA', true],
    'upper only required, lacks upper' => [['uppercase'], 'longpassword1', false],
    'lower only required, has lower' => [['lowercase'], 'LONGPASSWORDa', true],
    'lower only required, lacks lower' => [['lowercase'], 'LONGPASSWORD1', false],
    'digits required' => [['digits'], 'longpassword7', true],
    'digits missing' => [['digits'], 'longpasswordX', false],
    'special required, has special' => [['special_chars'], 'longpassword!', true],
    'special required, has tilde' => [['special_chars'], 'longpassword~', true],
    'special required, none' => [['special_chars'], 'longpassword1A', false],
    'all four satisfied' => [['uppercase', 'lowercase', 'digits', 'special_chars'], 'Longpass1!', true],
    'all four, one missing' => [['uppercase', 'lowercase', 'digits', 'special_chars'], 'Longpass1x', false],
    'nothing required' => [[], 'longpassword', true],
]);

test('the failure message names every missing class', function () {
    Setting::set('password_required_char_classes', ['uppercase', 'digits', 'special_chars']);

    $validator = Illuminate\Support\Facades\Validator::make(['password' => 'alllowercase'], ['password' => Illuminate\Validation\Rules\Password::default()]);

    expect($validator->errors()->first('password'))->toContain('英大文字')->toContain('数字')->toContain('記号');
});

test('unknown stored classes are ignored', function () {
    Setting::set('password_required_char_classes', ['bogus', 'digits']);

    expect(App\Rules\RequiredPasswordCharacterClasses::required())->toBe(['digits']);
});

test('registration, the admin form and password reset all enforce the classes', function () {
    Setting::set('password_required_char_classes', ['uppercase', 'digits']);

    expect(fn () => app(App\Actions\Fortify\CreateNewUser::class)->create(charClassPasswordInput('alllowercasepassword')))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $ok = app(App\Actions\Fortify\CreateNewUser::class)->create(charClassPasswordInput('GoodPassword123'));
    expect($ok->login)->toBe('charclass');

    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)->test('users.form')
        ->set('name', 'Someone')->set('login', 'someone')->set('email', 'someone@example.com')
        ->set('password', 'alllowercasepassword')->set('password_confirmation', 'alllowercasepassword')
        ->call('save')
        ->assertHasErrors('password');

    $user = User::factory()->create();
    expect(fn () => app(App\Actions\Fortify\ResetUserPassword::class)->reset($user, ['password' => 'alllowercasepassword', 'password_confirmation' => 'alllowercasepassword']))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('a blank password is left to the required rules rather than the class rule', function () {
    Setting::set('password_required_char_classes', ['uppercase']);

    $validator = Illuminate\Support\Facades\Validator::make(['password' => ''], ['password' => ['nullable', Illuminate\Validation\Rules\Password::default()]]);

    expect($validator->passes())->toBeTrue();
});
