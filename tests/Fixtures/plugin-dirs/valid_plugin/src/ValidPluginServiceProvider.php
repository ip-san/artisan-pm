<?php

declare(strict_types=1);

namespace ArtisanPmFixture\ValidPlugin;

use App\Support\Plugins\Plugin;
use App\Support\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

/**
 * A plugin loaded from disk by PluginLoader in tests/Feature/Plugins/PluginLoaderTest.php.
 */
final class ValidPluginServiceProvider extends ServiceProvider
{
    public const string PERMISSION_KEY = 'valid_plugin_view_widget';

    public function boot(): void
    {
        $manager = $this->app->make(PluginManager::class);

        $manager->registerPlugin(new Plugin(
            id: 'valid_plugin',
            name: 'Valid Fixture Plugin',
            author: 'Fixture Author',
            version: '1.2.3',
            requiresCoreVersion: '1.0.0',
        ));

        $manager->registerPermission(self::PERMISSION_KEY);
    }
}
