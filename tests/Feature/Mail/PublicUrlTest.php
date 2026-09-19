<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Mail\PublicUrl;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('without a host name the configured APP_URL keeps being used', function () {
    expect(PublicUrl::root())->toBeNull();

    PublicUrl::apply();

    expect(url('/probe'))->toStartWith(rtrim((string) config('app.url'), '/'));
});

test('a host name and protocol point generated links at the public address', function () {
    Setting::set('host_name', 'pm.example.com/redmine');
    Setting::set('protocol', 'https');

    expect(PublicUrl::root())->toBe('https://pm.example.com/redmine');

    PublicUrl::apply();

    expect(url('/issues/1'))->toBe('https://pm.example.com/redmine/issues/1')
        ->and(route('projects.index'))->toStartWith('https://pm.example.com/redmine');
});

test('surrounding slashes and blanks are ignored and http is the default protocol', function () {
    Setting::set('host_name', ' /pm.example.com:8080/ ');

    expect(PublicUrl::root())->toBe('http://pm.example.com:8080');
});

test('links are rebuilt from the setting before every queued job', function () {
    Setting::set('host_name', 'jobs.example.com');
    URL::forceRootUrl('http://stale.example.com');

    // The closure is serialized for the queue, so it reports through the cache.
    dispatch(function (): void {
        Illuminate\Support\Facades\Cache::put('public-url-probe', url('/probe'));
    });

    expect(Illuminate\Support\Facades\Cache::get('public-url-probe'))->toBe('http://jobs.example.com/probe');
});

test('the settings form saves and validates the host name and protocol', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('host_name', 'pm.example.com/tracker')
        ->set('protocol', 'https')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('host_name'))->toBe('pm.example.com/tracker')->and(Setting::get('protocol'))->toBe('https');

    foreach (['bad host', 'http://pm.example.com', '-x.com', 'pm.example.com:99999999'] as $invalid) {
        Livewire::actingAs($admin)->test('settings.index')->set('host_name', $invalid)->call('save')->assertHasErrors(['host_name']);
    }

    Livewire::actingAs($admin)->test('settings.index')->set('host_name', '')->call('save')->assertHasNoErrors();
    expect(PublicUrl::root())->toBeNull();

    Livewire::actingAs($admin)->test('settings.index')->set('protocol', 'ftp')->call('save')->assertHasErrors(['protocol']);
});
