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
use Illuminate\Console\Scheduling\Schedule;
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

test('with the database queue, schedule:run also works off the queued jobs', function () {
    config(['queue.default' => 'database']);

    dispatch(fn () => cache()->put('scheduler-drained-queue', true))->onConnection('database');
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('schedule:run')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(cache()->get('scheduler-drained-queue'))->toBeTrue();
});

test('with the sync queue, schedule:run leaves the queue worker off', function () {
    config(['queue.default' => 'sync']);

    dispatch(fn () => cache()->put('scheduler-drained-queue', true))->onConnection('database');

    $this->artisan('schedule:run')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(cache()->get('scheduler-drained-queue'))->toBeNull();
});

test('the scheduled queue worker stops when the queue is empty and never overlaps', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'queue:work database');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});

test('SCHEDULE_TIMEZONE sets the scheduler\'s zone while stored times stay UTC', function () {
    expect(collect(app(Schedule::class)->events())->every(fn ($event) => $event->timezone === null))->toBeTrue();

    $_ENV['SCHEDULE_TIMEZONE'] = $_SERVER['SCHEDULE_TIMEZONE'] = 'Asia/Tokyo';

    try {
        $this->refreshApplication();
        $this->artisan('schedule:list')->assertSuccessful();

        expect(config('app.timezone'))->toBe('UTC')
            ->and(collect(app(Schedule::class)->events())->pluck('timezone')->unique()->all())->toBe(['Asia/Tokyo']);
    } finally {
        unset($_ENV['SCHEDULE_TIMEZONE'], $_SERVER['SCHEDULE_TIMEZONE']);
    }
});
