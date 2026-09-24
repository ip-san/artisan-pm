<?php

declare(strict_types=1);

namespace ArtisanPmFixture\ThrowingPlugin;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * A plugin whose boot() fails, which PluginLoader must log and skip.
 */
final class ThrowingPluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        throw new RuntimeException('Fixture plugin failed to boot.');
    }
}
