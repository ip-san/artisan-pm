<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Watcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Gate;

/**
 * Matches Redmine's `Watcher.prune` (app/models/watcher.rb), exposed there
 * as the `redmine:watchers:prune` rake task ("Removes watchers from what
 * they can no longer view") — run manually or via cron, not triggered
 * automatically on every membership/role change. Scheduled here instead of
 * left as an on-demand command, matching this app's existing
 * PruneExpiredPendingUploadsJob precedent for this class of maintenance
 * sweep.
 */
final class PruneUnwatchableWatchersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'prune-unwatchable-watchers';
    }

    public function handle(): void
    {
        Watcher::query()
            ->with(['watchable', 'user'])
            ->lazy()
            ->each(function (Watcher $watcher): void {
                if ($watcher->user === null || $watcher->watchable === null) {
                    return;
                }

                if (! Gate::forUser($watcher->user)->allows('view', $watcher->watchable)) {
                    $watcher->delete();
                }
            });
    }
}
