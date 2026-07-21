<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Services\RepositorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs RepositorySyncService in the queue rather than inline on a web
 * request — shelling out to git for a large or slow-to-reach repository
 * shouldn't block an HTTP response.
 *
 * $timeout is kept under the database queue connection's retry_after
 * (90s, config/queue.php) on purpose: if a run took longer than
 * retry_after, the worker would treat it as abandoned and hand the same
 * job to another worker while the first was still running, racing to
 * insert the same changesets. 80s covers GitAdapter/SvnAdapter's own
 * longest single command (log(), timed out at 60s) plus headroom for the
 * per-commit DB writes, and RepositorySyncService::sync() advances
 * last_synced_revision after every commit it processes — so a run that
 * does hit this timeout on a large first sync simply resumes from where
 * it left off next time, rather than losing or duplicating work.
 *
 * ShouldBeUnique additionally guards against a duplicate sync (e.g. a
 * double-clicked "sync" button) racing itself, independent of timing.
 */
final class RepositorySyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 80;

    public int $uniqueFor = 90;

    public function __construct(
        private readonly Repository $repository,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function handle(RepositorySyncService $service): void
    {
        $service->sync($this->repository);
    }

    public function failed(Throwable $e): void
    {
        logger()->error('RepositorySyncJob failed', [
            'repository_id' => $this->repository->id,
            'error' => $e->getMessage(),
        ]);
    }
}
