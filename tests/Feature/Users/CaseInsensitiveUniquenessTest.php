<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function registrationInput(array $overrides = []): array
{
    return [
        'name' => 'New Person',
        'login' => 'newperson',
        'email' => 'new@example.com',
        'password' => 'a-Strong-Password-123',
        'password_confirmation' => 'a-Strong-Password-123',
        ...$overrides,
    ];
}

test('registration rejects a login or email that differs only in case', function () {
    User::factory()->create(['login' => 'Taken.Login', 'email' => 'Taken@Example.com']);

    foreach ([['login' => 'taken.login'], ['login' => 'TAKEN.LOGIN'], ['email' => 'taken@example.com'], ['email' => 'TAKEN@EXAMPLE.COM']] as $override) {
        expect(fn () => app(CreateNewUser::class)->create(registrationInput($override)))->toThrow(ValidationException::class);
    }

    expect(User::query()->count())->toBe(1);
});

test('registration still accepts genuinely new values', function () {
    User::factory()->create(['login' => 'someone', 'email' => 'someone@example.com']);

    $user = app(CreateNewUser::class)->create(registrationInput());

    expect($user->login)->toBe('newperson');
});

test('the admin user form rejects case-variant duplicates but allows saving a user unchanged', function () {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create(['login' => 'Existing', 'email' => 'Existing@Example.com']);
    $target = User::factory()->create(['login' => 'target', 'email' => 'target@example.com']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $target])
        ->set('login', 'EXISTING')
        ->set('email', 'existing@example.com')
        ->call('save')
        ->assertHasErrors(['login', 'email']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $existing])
        ->call('save')
        ->assertHasNoErrors(['login', 'email']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $existing])
        ->set('login', 'EXISTING')
        ->call('save')
        ->assertHasNoErrors(['login']);
});

test('the profile page and the API refuse an email that only differs in case from someone else\'s', function () {
    User::factory()->create(['email' => 'boss@example.com']);
    $user = User::factory()->create(['email' => 'me@example.com']);

    Livewire::actingAs($user)->test('profile.index')
        ->set('email', 'BOSS@example.com')
        ->call('updateProfile')
        ->assertHasErrors('email');

    Passport::actingAs($user);
    $this->putJson('/api/v1/my/account', ['email' => 'Boss@Example.com'])->assertUnprocessable();
    expect($user->fresh()->email)->toBe('me@example.com');
});

test('email domain rules now apply to the admin form for a new or changed address', function () {
    Setting::set('email_domains_denied', 'blocked.example');
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('users.form')
        ->set('name', 'Blocked Person')
        ->set('login', 'blockedperson')
        ->set('email', 'someone@blocked.example')
        ->set('password', 'a-Strong-Password-123')
        ->set('password_confirmation', 'a-Strong-Password-123')
        ->call('save')
        ->assertHasErrors('email');

    $existing = User::factory()->create(['email' => 'old@blocked.example']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $existing])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors('email');

    Livewire::actingAs($admin)->test('users.form', ['user' => $existing])
        ->set('email', 'other@blocked.example')
        ->call('save')
        ->assertHasErrors('email');
});

test('the allow list applies to the profile page and the API when the address changes', function () {
    Setting::set('email_domains_allowed', 'corp.example');
    $user = User::factory()->create(['email' => 'legacy@elsewhere.example']);

    Livewire::actingAs($user)->test('profile.index')
        ->set('name', 'Renamed')
        ->call('updateProfile')
        ->assertHasNoErrors('email');

    Livewire::actingAs($user)->test('profile.index')
        ->set('email', 'new@elsewhere.example')
        ->call('updateProfile')
        ->assertHasErrors('email');

    Livewire::actingAs($user)->test('profile.index')
        ->set('email', 'new@corp.example')
        ->call('updateProfile')
        ->assertHasNoErrors('email');

    Passport::actingAs($user->fresh());
    $this->putJson('/api/v1/my/account', ['email' => 'again@elsewhere.example'])->assertUnprocessable();
});

test('the domain policy honours deny over allow and treats a dot entry as subdomains only', function () {
    Setting::set('email_domains_allowed', '.corp.example');
    Setting::set('email_domains_denied', 'bad.corp.example');

    expect(App\Support\Auth\EmailDomainPolicy::allows('a@corp.example'))->toBeFalse()
        ->and(App\Support\Auth\EmailDomainPolicy::allows('a@team.corp.example'))->toBeTrue()
        ->and(App\Support\Auth\EmailDomainPolicy::allows('a@bad.corp.example'))->toBeFalse()
        ->and(App\Support\Auth\EmailDomainPolicy::allows('a@other.example'))->toBeFalse()
        ->and(App\Support\Auth\EmailDomainPolicy::allows('A@TEAM.CORP.EXAMPLE'))->toBeTrue();
});
