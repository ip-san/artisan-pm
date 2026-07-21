<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SamplePlugin;

use App\Support\Plugins\MenuItem;
use App\Support\Plugins\Plugin;
use App\Support\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

/**
 * A minimal, real plugin used only by the contract tests in
 * tests/Feature/Plugins — proves a third-party ServiceProvider outside
 * app/ can register a permission, a nav menu item, and a view hook
 * renderer purely through PluginManager's public API.
 */
final class SamplePluginServiceProvider extends ServiceProvider
{
    public const string PERMISSION_KEY = 'sample_plugin_view_widget';

    public static function metadata(): Plugin
    {
        return new Plugin(
            name: 'Sample Plugin',
            author: 'Contract Test Fixture',
            version: '1.0.0',
            requiresCoreVersion: '1.0.0',
        );
    }

    public function boot(): void
    {
        $manager = $this->app->make(PluginManager::class);

        $manager->registerPermission(self::PERMISSION_KEY);

        $manager->registerMenuItem('nav', new MenuItem(
            label: 'サンプルプラグイン',
            url: '/sample-plugin',
        ));

        $manager->registerViewHook(
            'issues.show.details_bottom',
            fn (array $data) => '<div data-sample-plugin-hook>Sample plugin says hello for issue #'.$data['issue']->id.'</div>',
        );
    }
}
