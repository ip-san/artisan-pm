<?php

use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Plugins\LifecycleHooks;
use App\Support\Plugins\PluginManager;

test('every event class under app/Events is offered as a lifecycle hook', function () {
    $offered = collect(LifecycleHooks::catalog())->pluck('event')->all();
    $classes = collect(glob(app_path('Events/*.php')))->map(fn (string $file) => 'App\\Events\\'.basename($file, '.php'))->all();

    expect(array_diff($classes, $offered))->toBe([])->and(array_diff($offered, $classes))->toBe([]);
});

test('hook names are unique and each maps to one event', function () {
    $events = collect(LifecycleHooks::catalog())->pluck('event');

    expect($events->unique()->count())->toBe($events->count())
        ->and(LifecycleHooks::eventFor('issue.created'))->toBe(App\Events\IssueCreated::class)
        ->and(LifecycleHooks::eventFor('nope'))->toBeNull();
});

test('a plugin subscribes to a hook by name and receives the finished issue', function () {
    $seen = [];
    app(PluginManager::class)->onLifecycle('issue.created', function (App\Events\IssueCreated $event) use (&$seen) {
        $seen[] = $event->issue->fresh()->subject;
    });
    $project = Project::factory()->create();

    app(IssueService::class)->create([
        'project_id' => $project->id, 'tracker_id' => Tracker::factory()->create()->id, 'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id, 'subject' => 'Observed',
    ], User::factory()->create());

    expect($seen)->toBe(['Observed']);
});

test('an unknown hook name is refused with the list of valid ones', function () {
    expect(fn () => app(PluginManager::class)->onLifecycle('issue.exploded', fn () => null))
        ->toThrow(InvalidArgumentException::class, 'issue.created');
});
