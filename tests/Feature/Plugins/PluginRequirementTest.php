<?php

use App\Support\Plugins\Plugin;
use App\Support\Plugins\PluginManager;
use App\Support\Plugins\PluginRequirementException;

function pluginRequiring(string $requiresCoreVersion): Plugin
{
    return new Plugin(
        id: 'requirement_test_plugin',
        name: 'Requirement Test Plugin',
        author: 'Test',
        version: '1.0.0',
        requiresCoreVersion: $requiresCoreVersion,
    );
}

test('a plugin requiring the current core version or lower registers successfully', function () {
    config(['plugins.core_version' => '2.0.0']);

    app(PluginManager::class)->registerPlugin(pluginRequiring('1.5.0'));

    expect(app(PluginManager::class)->plugin('requirement_test_plugin'))->not->toBeNull();
});

test('a plugin requiring exactly the current core version registers successfully', function () {
    config(['plugins.core_version' => '1.0.0']);

    app(PluginManager::class)->registerPlugin(pluginRequiring('1.0.0'));

    expect(app(PluginManager::class)->plugin('requirement_test_plugin'))->not->toBeNull();
});

test('a plugin requiring a newer core version than what is running throws and is not registered', function () {
    config(['plugins.core_version' => '1.0.0']);

    expect(fn () => app(PluginManager::class)->registerPlugin(pluginRequiring('2.0.0')))
        ->toThrow(PluginRequirementException::class, 'requires core version 2.0.0 or higher, but the running core version is 1.0.0');

    expect(app(PluginManager::class)->plugin('requirement_test_plugin'))->toBeNull();
});

test('a plugin whose requirement is not met does not leak its settings defaults either', function () {
    config(['plugins.core_version' => '1.0.0']);

    try {
        app(PluginManager::class)->registerPlugin(pluginRequiring('2.0.0'), settingsDefaults: ['foo' => 'bar']);
    } catch (PluginRequirementException) {
        // expected
    }

    expect(app(PluginManager::class)->hasSettings('requirement_test_plugin'))->toBeFalse();
});
