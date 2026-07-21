<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);
    }
}
