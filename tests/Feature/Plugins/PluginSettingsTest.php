<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Plugins\Plugin;
use App\Support\Plugins\PluginManager;
use Livewire\Livewire;
use Tests\Fixtures\Plugins\SamplePlugin\SamplePluginServiceProvider;

test('a registered plugin appears in the admin plugins list', function () {
    $this->app->register(SamplePluginServiceProvider::class);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('plugins.index'))
        ->assertOk()
        ->assertSee('Sample Plugin')
        ->assertSee('1.0.0')
        ->assertSee(route('plugins.settings', 'sample_plugin'), escape: false);
});

test('a plugin registered with no settings has no settings link', function () {
    $manager = app(PluginManager::class);
    $manager->registerPlugin(new Plugin(
        id: 'no_settings_plugin',
        name: 'No Settings Plugin',
        author: 'Test',
        version: '1.0.0',
        requiresCoreVersion: '1.0.0',
    ));
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('plugins.index'))
        ->assertOk()
        ->assertSee('No Settings Plugin')
        ->assertDontSee(route('plugins.settings', 'no_settings_plugin'), escape: false);
});

test('a non-admin cannot access the plugins list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('plugins.index'))->assertForbidden();
});

test('an admin can view and update a plugin\'s settings through the real save path', function () {
    $this->app->register(SamplePluginServiceProvider::class);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('plugins.settings', ['plugin' => 'sample_plugin'])
        ->assertSet('values.greeting', 'Hello')
        ->assertSet('values.enabled', true)
        ->assertSet('values.max_items', 10)
        ->set('values.greeting', 'Konnichiwa')
        ->set('values.enabled', false)
        ->set('values.max_items', '25')
        ->call('save');

    // Exercises the real read path (PluginManager::settings(), the same
    // method the settings form itself calls on mount) rather than reading
    // the Setting row directly. max_items round-trips as a real int, not
    // the string every submitted form field arrives as.
    $settings = app(PluginManager::class)->settings('sample_plugin');
    expect($settings)->toBe([
        'greeting' => 'Konnichiwa',
        'enabled' => false,
        'max_items' => 25,
    ])->and($settings['max_items'])->toBeInt();
});

test('save() re-authorizes and re-validates the plugin id rather than trusting client state', function () {
    $this->app->register(SamplePluginServiceProvider::class);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('plugins.settings', ['plugin' => 'sample_plugin'])
        ->set('pluginId', 'does_not_exist')
        ->call('save')
        ->assertStatus(404);

    expect(Setting::get('plugin_does_not_exist'))->toBeNull();
});

test('a non-admin cannot access a plugin\'s settings page', function () {
    $this->app->register(SamplePluginServiceProvider::class);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('plugins.settings', ['plugin' => 'sample_plugin'])
        ->assertForbidden();
});

test('visiting the settings page for an unregistered plugin id is a 404', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('plugins.settings', ['plugin' => 'does_not_exist'])
        ->assertStatus(404);
});

test('saving plugin settings ignores keys not in the declared defaults', function () {
    $this->app->register(SamplePluginServiceProvider::class);
    $manager = app(PluginManager::class);

    $manager->saveSettings('sample_plugin', [
        'greeting' => 'Bonjour',
        'enabled' => true,
        'max_items' => 10,
        'not_a_declared_key' => 'injected',
    ]);

    expect(Setting::get('plugin_sample_plugin'))->toBe([
        'greeting' => 'Bonjour',
        'enabled' => true,
        'max_items' => 10,
    ]);
});

test('PluginManager::settings falls back to declared defaults when nothing is stored yet', function () {
    $this->app->register(SamplePluginServiceProvider::class);

    expect(app(PluginManager::class)->settings('sample_plugin'))->toBe([
        'greeting' => 'Hello',
        'enabled' => true,
        'max_items' => 10,
    ]);
});
