<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Locale\SupportedLocales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function localeRequest(?string $acceptLanguage): Request
{
    $request = Request::create('/');

    if ($acceptLanguage !== null) {
        $request->headers->set('Accept-Language', $acceptLanguage);
    }

    return $request;
}

test('the default language is English until the setting says otherwise', function () {
    expect(SupportedLocales::default())->toBe('en');

    Setting::set('default_language', 'ja');
    expect(SupportedLocales::default())->toBe('ja');

    Setting::set('default_language', 'fr');
    expect(SupportedLocales::default())->toBe('en');
});

test('a signed-in user gets their own language unless the default is forced', function () {
    Setting::set('default_language', 'en');
    $user = User::factory()->create(['language' => 'ja']);

    expect(SupportedLocales::resolve($user, localeRequest('en')))->toBe('ja');

    Setting::set('force_default_language_for_loggedin', true);
    expect(SupportedLocales::resolve($user, localeRequest('ja')))->toBe('en');
});

test('a user with no or an unknown language falls back to the default', function () {
    Setting::set('default_language', 'ja');

    expect(SupportedLocales::resolve(User::factory()->create(['language' => null]), localeRequest(null)))->toBe('ja')
        ->and(SupportedLocales::resolve(User::factory()->create(['language' => 'xx']), localeRequest(null)))->toBe('ja');
});

test('a visitor follows the browser unless the default is forced', function () {
    Setting::set('default_language', 'en');

    expect(SupportedLocales::resolve(null, localeRequest('ja,en;q=0.5')))->toBe('ja')
        ->and(SupportedLocales::resolve(null, localeRequest('fr')))->toBe('en')
        ->and(SupportedLocales::resolve(null, localeRequest(null)))->toBe('en');

    Setting::set('force_default_language_for_anonymous', true);
    expect(SupportedLocales::resolve(null, localeRequest('ja')))->toBe('en');
});

test('the request runs in the resolved language', function () {
    $user = User::factory()->create(['language' => 'ja']);

    $this->actingAs($user)->get(route('profile.index'))->assertOk();

    expect(app()->getLocale())->toBe('ja')->and(Carbon::getLocale())->toBe('ja');
});

test('the translation files answer for the English UI and leave the original for Japanese', function () {
    app()->setLocale('en');
    expect(__('言語'))->toBe('Language');

    app()->setLocale('ja');
    expect(__('言語'))->toBe('言語');
});

test('the profile saves the language, and blank clears it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->set('language', 'ja')->call('updateProfile')->assertHasNoErrors();
    expect($user->fresh()->language)->toBe('ja');

    Livewire::actingAs($user)->test('profile.index')->set('language', '')->call('updateProfile')->assertHasNoErrors();
    expect($user->fresh()->language)->toBeNull();

    Livewire::actingAs($user)->test('profile.index')->set('language', 'xx')->call('updateProfile')->assertHasErrors('language');
});

test('the settings form saves the default language and the two force options', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test('settings.index')
        ->set('default_language', 'ja')
        ->set('force_default_language_for_anonymous', true)
        ->call('save')->assertHasNoErrors();

    expect(Setting::get('default_language'))->toBe('ja')
        ->and(Setting::get('force_default_language_for_anonymous'))->toBeTrue()
        ->and(Setting::get('force_default_language_for_loggedin'))->toBeFalse();

    Livewire::actingAs(User::factory()->admin()->create())->test('settings.index')
        ->set('default_language', 'fr')->call('save')->assertHasErrors('default_language');
});
