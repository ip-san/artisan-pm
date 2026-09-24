<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Plugins\PluginLoader;
use App\Support\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * The permission tests/Fixtures/plugin-dirs/valid_plugin registers (a literal:
 * the fixture's class isn't autoloadable until the plugin is loaded).
 */
const VALID_PLUGIN_PERMISSION = 'valid_plugin_view_widget';

/**
 * A fresh loader reading the given plugins folder (the app's own loader ran
 * at boot against the default, empty plugins/).
 */
function pluginLoaderFor(string $path): PluginLoader
{
    config(['plugins.path' => $path]);
    app()->forgetInstance(PluginLoader::class);

    return app(PluginLoader::class);
}

function fixturePluginsPath(): string
{
    return base_path('tests/Fixtures/plugin-dirs');
}

test('the default plugins folder loads nothing and the app boots', function () {
    expect(config('plugins.path'))->toBe(base_path('plugins'))
        ->and(app(PluginManager::class)->plugin('valid_plugin'))->toBeNull();

    $this->get(route('login'))->assertOk();
});

test('valid plugins are discovered with their manifest details but not loaded until enabled', function () {
    $loader = pluginLoaderFor(fixturePluginsPath());
    $loader->loadEnabled();

    $manifest = $loader->discovered()['valid_plugin'];

    expect($manifest->name)->toBe('Valid Fixture Plugin')
        ->and($manifest->version)->toBe('1.2.3')
        ->and($manifest->author)->toBe('Fixture Author')
        ->and($manifest->provider)->toBe('ArtisanPmFixture\\ValidPlugin\\ValidPluginServiceProvider')
        ->and($loader->isLoaded('valid_plugin'))->toBeFalse()
        ->and(app(PluginManager::class)->plugin('valid_plugin'))->toBeNull()
        ->and(app(PermissionRegistry::class)->has(VALID_PLUGIN_PERMISSION))->toBeFalse();
});

test('an enabled plugin has its classes autoloaded and its provider registered', function () {
    Setting::set('plugins_enabled', ['valid_plugin']);

    $loader = pluginLoaderFor(fixturePluginsPath());
    $loader->loadEnabled();

    expect($loader->isLoaded('valid_plugin'))->toBeTrue()
        ->and(app(PluginManager::class)->plugin('valid_plugin')?->name)->toBe('Valid Fixture Plugin')
        ->and(app(PermissionRegistry::class)->has(VALID_PLUGIN_PERMISSION))->toBeTrue();
});

test('invalid manifests are rejected with a reason', function (string $folder, string $reason) {
    $loader = pluginLoaderFor(fixturePluginsPath());

    expect($loader->discovered())->not->toHaveKey($folder)
        ->and($loader->failures()[$folder] ?? '')->toContain($reason);
})->with([
    'broken JSON' => ['bad_json', 'not valid JSON'],
    'id not matching the folder' => ['id_mismatch', 'must match the folder name'],
    'a .. in an autoload path' => ['dotdot_path', 'must not contain ".."'],
    'an absolute autoload path' => ['absolute_path', 'must be relative'],
    'a provider outside the declared namespaces' => ['outside_namespace', 'must be inside one of the namespaces'],
    'the app\'s own namespace' => ['reserved_namespace', 'reserved for the application'],
    'no plugin.json' => ['no_manifest', 'plugin.json is missing'],
]);

test('an autoload folder that escapes the plugin through a symlink is rejected', function () {
    $root = storage_path('framework/testing/plugins-'.uniqid());
    File::ensureDirectoryExists("{$root}/linked_plugin");
    File::ensureDirectoryExists("{$root}/outside/src");
    symlink("{$root}/outside/src", "{$root}/linked_plugin/src");
    File::put("{$root}/linked_plugin/plugin.json", json_encode([
        'id' => 'linked_plugin',
        'provider' => 'ArtisanPmFixture\\LinkedPlugin\\Provider',
        'autoload' => ['ArtisanPmFixture\\LinkedPlugin\\' => 'src/'],
    ]));

    try {
        $loader = pluginLoaderFor($root);

        expect($loader->discovered())->not->toHaveKey('linked_plugin')
            ->and($loader->failures()['linked_plugin'])->toContain('resolves outside the plugin folder');
    } finally {
        File::deleteDirectory($root);
    }
});

test('a plugin folder that is a symlink to somewhere outside the plugins folder is rejected', function () {
    $root = storage_path('framework/testing/plugins-'.uniqid());
    File::ensureDirectoryExists("{$root}/plugins");
    symlink(fixturePluginsPath().'/valid_plugin', "{$root}/plugins/valid_plugin");

    try {
        $loader = pluginLoaderFor("{$root}/plugins");

        expect($loader->discovered())->toBe([])
            ->and($loader->failures()['valid_plugin'])->toContain('outside the plugins directory');
    } finally {
        File::deleteDirectory($root);
    }
});

test('broken enabled plugins are logged and skipped without taking the app down', function () {
    Log::spy();
    Setting::set('plugins_enabled', ['throwing_plugin', 'newer_core_plugin', 'missing_provider', 'bad_json', 'valid_plugin']);

    $loader = pluginLoaderFor(fixturePluginsPath());
    $loader->loadEnabled();

    expect($loader->isLoaded('throwing_plugin'))->toBeFalse()
        ->and($loader->failures()['throwing_plugin'])->toContain('Fixture plugin failed to boot.')
        ->and($loader->failures()['newer_core_plugin'])->toContain('Requires core version 99.0.0')
        ->and($loader->failures()['missing_provider'])->toContain('is not a ServiceProvider class')
        ->and($loader->isLoaded('valid_plugin'))->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'throwing_plugin'));

    $this->get(route('login'))->assertOk();
});

test('an unreadable enabled list means no plugin is enabled', function () {
    Setting::set('plugins_enabled', 'valid_plugin');

    expect(PluginLoader::enabledIds())->toBe([]);
});

test('a permission granted to a role grants nothing while its plugin is disabled', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => [VALID_PLUGIN_PERMISSION]]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    pluginLoaderFor(fixturePluginsPath())->loadEnabled();

    expect(app(AuthorizationService::class)->can($user, VALID_PLUGIN_PERMISSION, $project))->toBeFalse()
        ->and($role->fresh()->hasPermission(VALID_PLUGIN_PERMISSION))->toBeTrue();

    Setting::set('plugins_enabled', ['valid_plugin']);
    pluginLoaderFor(fixturePluginsPath())->loadEnabled();
    app(AuthorizationService::class)->flushCache();

    expect(app(AuthorizationService::class)->can($user, VALID_PLUGIN_PERMISSION, $project))->toBeTrue();
});

test('saving a role while a plugin is disabled keeps the plugin\'s granted permission', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::factory()->create(['name' => 'Plugin role', 'permissions' => ['view_issues', VALID_PLUGIN_PERMISSION]]);

    Livewire::actingAs($admin)->test('roles.form', ['role' => $role])
        ->set('permissions', ['view_issues', 'add_issues'])
        ->call('save')->assertHasNoErrors();

    expect($role->fresh()->permissionKeys())->toEqualCanonicalizing(['view_issues', 'add_issues', VALID_PLUGIN_PERMISSION]);

    Livewire::actingAs($admin)->test('roles.report')->call('save');

    expect($role->fresh()->hasPermission(VALID_PLUGIN_PERMISSION))->toBeTrue();
});
