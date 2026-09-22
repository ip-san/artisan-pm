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
