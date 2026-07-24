<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PendingUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes PendingUpload rows (and their Media, via the model's own
 * cascade/observer behavior) whose token was never redeemed within the
 * window — matches Redmine having no equivalent GC job at all (an
 * unclaimed Attachment there just sits with a null container forever),
 * but this app's PendingUpload rows exist purely as a holder, so leaving
 * them around indefinitely would accumulate real uploaded file storage
 * for no reason. An hour is generous for "upload, then immediately
 * create/update the issue that references it" — the actual API flow this
 * exists for.
 */
final class PruneExpiredPendingUploadsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const int EXPIRY_MINUTES = 60;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'prune-expired-pending-uploads';
    }

    public function handle(): void
    {
        PendingUpload::query()
            ->where('created_at', '<', now()->subMinutes(self::EXPIRY_MINUTES))
            ->lazy()
            ->each(fn (PendingUpload $upload) => $upload->delete());
    }
}
