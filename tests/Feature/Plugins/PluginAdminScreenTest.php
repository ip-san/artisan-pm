<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Plugins\PluginLoader;
use Livewire\Livewire;

/**
 * Points the app's plugin loader at the checked-in fixture plugins.
 */
function useFixturePluginFolder(): PluginLoader
{
    config(['plugins.path' => base_path('tests/Fixtures/plugin-dirs')]);
    app()->forgetInstance(PluginLoader::class);

    return app(PluginLoader::class);
}

test('the plugins screen lists the plugins found on disk as disabled, and the folders it cannot read', function () {
    useFixturePluginFolder();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('plugins.index'))
        ->assertOk()
        ->assertSee('Valid Fixture Plugin')
        ->assertSee('v1.2.3')
        ->assertSee('Loads cleanly.')
        ->assertSee('有効にする')
        ->assertSee('読み込めないプラグイン')
        ->assertSee('plugins/bad_json')
        ->assertSee('must not contain &quot;..&quot;', false);
});

test('an admin enables a plugin, which is then loaded, and disables it again', function () {
    useFixturePluginFolder();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('plugins.index')
        ->call('enable', 'valid_plugin')
        ->assertRedirect(route('plugins.index'));

    expect(Setting::get('plugins_enabled'))->toBe(['valid_plugin']);

    useFixturePluginFolder()->loadEnabled();

    $this->actingAs($admin)->get(route('plugins.index'))
        ->assertSee('無効にする')
        ->assertSeeInOrder(['Valid Fixture Plugin', '有効']);

    Livewire::actingAs($admin)->test('plugins.index')
        ->call('disable', 'valid_plugin')
        ->assertRedirect(route('plugins.index'));

    expect(Setting::get('plugins_enabled'))->toBe([]);
});

test('an enabled plugin that fails to load shows why', function () {
    Setting::set('plugins_enabled', ['throwing_plugin']);
    useFixturePluginFolder()->loadEnabled();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('plugins.index'))
        ->assertOk()
        ->assertSee('読み込み失敗')
        ->assertSee('Fixture plugin failed to boot.');
});

test('only plugins found on disk can be enabled', function () {
    useFixturePluginFolder();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('plugins.index')->call('enable', 'bad_json')->assertNotFound();
    Livewire::actingAs($admin)->test('plugins.index')->call('enable', 'not_there')->assertNotFound();

    expect(Setting::get('plugins_enabled', []))->toBe([]);
});

test('a non-admin can neither see the screen nor enable a plugin', function () {
    useFixturePluginFolder();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('plugins.index'))->assertForbidden();
    Livewire::actingAs($user)->test('plugins.index')->assertForbidden();

    expect(Setting::get('plugins_enabled', []))->toBe([]);
});
