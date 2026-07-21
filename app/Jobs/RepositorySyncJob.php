<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Services\RepositorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs RepositorySyncService in the queue rather than inline on a web
 * request — shelling out to git for a large or slow-to-reach repository
 * shouldn't block an HTTP response.
 */
final class RepositorySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly Repository $repository,
    ) {}

    public function handle(RepositorySyncService $service): void
    {
        $service->sync($this->repository);
    }
}
