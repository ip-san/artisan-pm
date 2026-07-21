<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Dashboard\Blocks\AssignedIssuesBlock;
use App\Support\Dashboard\Blocks\LatestNewsBlock;
use App\Support\Dashboard\Blocks\ReportedIssuesBlock;
use App\Support\Dashboard\Blocks\TimeEntriesBlock;
use App\Support\Dashboard\Blocks\WatchedIssuesBlock;
use App\Support\Dashboard\DashboardBlockRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the catalog of blocks a user can add to their My Page — same
 * registration shape as ActivityServiceProvider/PermissionServiceProvider,
 * with a future plugin system expected to register its own blocks
 * alongside these.
 */
final class DashboardBlockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DashboardBlockRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(DashboardBlockRegistry::class);

        $registry->register($this->app->make(AssignedIssuesBlock::class));
        $registry->register($this->app->make(ReportedIssuesBlock::class));
        $registry->register($this->app->make(WatchedIssuesBlock::class));
        $registry->register($this->app->make(LatestNewsBlock::class));
        $registry->register($this->app->make(TimeEntriesBlock::class));
    }
}
