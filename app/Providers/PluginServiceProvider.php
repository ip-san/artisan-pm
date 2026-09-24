<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Plugins\PluginLoader;
use App\Support\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);
        $this->app->singleton(PluginLoader::class);
    }

    /**
     * Enabled plugins under config('plugins.path') are loaded once every
     * provider has booted: settings are read through Eloquent, which isn't
     * usable before then, and registering a provider on a booted app runs
     * its boot() right away, inside PluginLoader's error handling.
     */
    public function boot(): void
    {
        $this->app->booted(fn () => $this->app->make(PluginLoader::class)->loadEnabled());
    }
}
