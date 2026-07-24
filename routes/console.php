<?php

use App\Jobs\AutofetchRepositoryChangesetsJob;
use App\Jobs\ProcessIncomingMailJob;
use App\Jobs\PruneExpiredPendingUploadsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessIncomingMailJob)->everyFiveMinutes();
Schedule::job(new AutofetchRepositoryChangesetsJob)->everyFifteenMinutes();
Schedule::job(new PruneExpiredPendingUploadsJob)->hourly();
