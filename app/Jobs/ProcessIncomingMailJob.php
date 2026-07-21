<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\IncomingMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Polls the configured mailbox for unread messages and creates issues from
 * them — run on a schedule (see routes/console.php) rather than the web
 * request cycle, since it's pure background IO with no user waiting on it.
 *
 * $timeout is kept under the database queue connection's retry_after (90s,
 * config/queue.php) — same reasoning as RepositorySyncJob: a run that took
 * longer than retry_after would be treated as abandoned and handed to
 * another worker while the first was still going, racing to create
 * duplicate issues from the same messages.
 *
 * ShouldBeUnique guards against two scheduled ticks overlapping if a run
 * takes longer than the schedule interval — there's only ever one mailbox
 * being polled, so a fixed uniqueId is enough.
 */
final class ProcessIncomingMailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'incoming-mail';
    }

    public function handle(IncomingMailService $service): void
    {
        $service->fetchAndProcess();
    }

    public function failed(Throwable $e): void
    {
        logger()->error('ProcessIncomingMailJob failed', ['error' => $e->getMessage()]);
    }
}
