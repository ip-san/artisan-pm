<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatches a RepositorySyncJob for every repository on a schedule (see
 * routes/console.php), matching Redmine's autofetch_changesets setting.
 * Gated behind that setting so it's opt-in — shelling out to every
 * configured repository's VCS on every tick isn't free. RepositorySyncJob's
 * own ShouldBeUnique (keyed per repository) already prevents this from
 * piling up jobs behind a repository whose sync is still running.
 */
final class AutofetchRepositoryChangesetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'repository-autofetch';
    }

    public function handle(): void
    {
        if (! Setting::get('autofetch_changesets', false)) {
            return;
        }

        Repository::query()->lazy()->each(fn (Repository $repository) => RepositorySyncJob::dispatch($repository));
    }
}
