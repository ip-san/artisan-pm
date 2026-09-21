<?php

use App\Jobs\AutofetchRepositoryChangesetsJob;
use App\Jobs\ProcessIncomingMailJob;
use App\Jobs\PruneExpiredPendingUploadsJob;
use App\Jobs\PruneUnwatchableWatchersJob;
use App\Jobs\RepositorySyncJob;
use App\Models\PendingUpload;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Midnight is due for every schedule in routes/console.php.
    $this->travelTo(now()->startOfDay());
});

test('every scheduled job is dispatched on the scheduler connection', function () {
    Queue::fake();

    $this->artisan('schedule:run')->assertSuccessful();

    foreach ([
        ProcessIncomingMailJob::class,
        AutofetchRepositoryChangesetsJob::class,
        PruneExpiredPendingUploadsJob::class,
        PruneUnwatchableWatchersJob::class,
    ] as $job) {
        Queue::assertPushed($job, fn ($pushed) => $pushed->connection === config('queue.scheduler_connection'));
    }
});

test('the scheduler connection defaults to sync so no queue worker is needed', function () {
    expect(config('queue.scheduler_connection'))->toBe('sync');
});

test('scheduled jobs run inside schedule:run even when the default queue is the database and no worker exists', function () {
    config(['queue.default' => 'database']);

    $upload = PendingUpload::factory()->for(User::factory()->create())->create(['created_at' => now()->subHours(2)]);

    $this->artisan('schedule:run')->assertSuccessful();

    expect(PendingUpload::find($upload->id))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('the autofetch job syncs repositories on its own connection rather than the default queue', function () {
    Queue::fake();
    Setting::set('autofetch_changesets', true);
    Repository::factory()->for(Project::factory())->create();

    (new AutofetchRepositoryChangesetsJob)->handle();

    Queue::assertPushed(RepositorySyncJob::class, fn ($job) => $job->connection === config('queue.scheduler_connection'));
});

test('the autofetch job hands repository syncs to the connection it was queued on', function () {
    Queue::fake();
    Setting::set('autofetch_changesets', true);
    Repository::factory()->for(Project::factory())->create();

    $job = new AutofetchRepositoryChangesetsJob;
    $job->onConnection('database');
    $job->handle();

    Queue::assertPushed(RepositorySyncJob::class, fn ($sync) => $sync->connection === 'database');
});
