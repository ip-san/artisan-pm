<?php

use App\Jobs\AutofetchRepositoryChangesetsJob;
use App\Jobs\ProcessIncomingMailJob;
use App\Jobs\PruneExpiredPendingUploadsJob;
use App\Jobs\PruneUnwatchableWatchersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::job() only enqueues a ShouldQueue job, so without a queue worker
// (shared hosting) it would never run; queue.scheduler_connection defaults to
// "sync" to run each one inside `schedule:run` itself.
$schedulerConnection = config('queue.scheduler_connection');

Schedule::job(new ProcessIncomingMailJob, connection: $schedulerConnection)->everyFiveMinutes();
Schedule::job(new AutofetchRepositoryChangesetsJob, connection: $schedulerConnection)->everyFifteenMinutes();
Schedule::job(new PruneExpiredPendingUploadsJob, connection: $schedulerConnection)->hourly();
Schedule::job(new PruneUnwatchableWatchersJob, connection: $schedulerConnection)->daily();

// Shared hosting has no resident queue worker: with QUEUE_CONNECTION=database
// the single `schedule:run` cron line also drains the queue (notification
// mail, webhooks, CSV imports). The worker runs inside schedule:run (not as a
// separate process, which would need proc_open) and stops once the queue is
// empty or after 50 seconds. withoutOverlapping keeps a long job (an import)
// from being picked up by a second worker meanwhile; its lock expires after
// 15 minutes should the host kill the process. With QUEUE_CONNECTION=sync
// everything already runs inline and this is skipped.
Schedule::call(fn () => Artisan::call('queue:work', [
    'connection' => 'database',
    '--queue' => 'default,webhooks',
    '--stop-when-empty' => true,
    '--max-time' => 50,
]))
    ->name('queue:work database')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->when(fn (): bool => config('queue.default') === 'database');
