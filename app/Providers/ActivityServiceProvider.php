<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Activity\Providers\ChangesetActivityProvider;
use App\Support\Activity\Providers\DocumentActivityProvider;
use App\Support\Activity\Providers\IssueActivityProvider;
use App\Support\Activity\Providers\IssueJournalActivityProvider;
use App\Support\Activity\Providers\MessageActivityProvider;
use App\Support\Activity\Providers\NewsActivityProvider;
use App\Support\Activity\Providers\TimeEntryActivityProvider;
use App\Support\Activity\Providers\WikiActivityProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers each module's contribution to the aggregated activity feed.
 * A future plugin system would call ActivityProviderRegistry::register()
 * the same way from its own service providers, matching how
 * PermissionServiceProvider is the single core registration point today
 * with plugins expected to add their own alongside it.
 */
final class ActivityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityProviderRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ActivityProviderRegistry::class);

        $registry->register($this->app->make(IssueActivityProvider::class));
        $registry->register($this->app->make(IssueJournalActivityProvider::class));
        $registry->register($this->app->make(WikiActivityProvider::class));
        $registry->register($this->app->make(MessageActivityProvider::class));
        $registry->register($this->app->make(NewsActivityProvider::class));
        $registry->register($this->app->make(DocumentActivityProvider::class));
        $registry->register($this->app->make(ChangesetActivityProvider::class));
        $registry->register($this->app->make(TimeEntryActivityProvider::class));
    }
}
