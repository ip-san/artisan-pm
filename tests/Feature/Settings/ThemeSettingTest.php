<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Preferences\UserPreferences;
use Livewire\Livewire;

test('pages render the light theme by default, for guests too', function () {
    $this->get(route('login'))->assertOk()->assertSee('data-theme="light"', false);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.index'))->assertOk()->assertSee('data-theme="light"', false);
});

test('an admin picks the site theme, which guests and users without a preference get', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('ui_theme', 'light')
        ->assertSeeHtml('data-ui-theme')
        ->set('ui_theme', 'dark')
        ->call('save')->assertHasNoErrors();

    expect(Setting::get('ui_theme'))->toBe('dark');

    auth()->logout();
    $this->get(route('login'))->assertSee('data-theme="dark"', false);
    $this->actingAs(User::factory()->create())
        ->get(route('profile.index'))->assertSee('data-theme="dark"', false);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('ui_theme', 'neon')
        ->call('save')->assertHasErrors('ui_theme');
});

test('a user overrides the site theme in their preferences and can go back to the site default', function () {
    Setting::set('ui_theme', 'dark');
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')
        ->assertSet('ui_theme', '')
        ->set('ui_theme', 'system')
        ->call('savePreferences')->assertHasNoErrors();

    expect($user->fresh()->preference('ui_theme'))->toBe('system');
    $this->actingAs($user->fresh())->get(route('profile.index'))->assertSee('data-theme="system"', false);

    Livewire::actingAs($user->fresh())->test('profile.index')
        ->set('ui_theme', 'neon')
        ->call('savePreferences')->assertHasErrors('ui_theme');

    Livewire::actingAs($user->fresh())->test('profile.index')
        ->set('ui_theme', '')
        ->call('savePreferences')->assertHasNoErrors();

    expect(UserPreferences::theme($user->fresh()))->toBe('dark');
});

test('an unknown stored theme falls back to light', function () {
    Setting::set('ui_theme', 'neon');

    expect(UserPreferences::siteTheme())->toBe('light')
        ->and(UserPreferences::theme(null))->toBe('light');
});
