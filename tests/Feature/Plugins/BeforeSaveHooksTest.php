<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use App\Services\TimeEntryService;
use App\Support\Plugins\BeforeSaveContext;
use App\Support\Plugins\BeforeSaveHooks;
use App\Support\Plugins\PluginManager;
use Illuminate\Validation\ValidationException;

test('every before-save hook maps to a real model class, and hook names are unique', function () {
    $catalog = BeforeSaveHooks::catalog();

    expect(array_keys($catalog))->toBe(array_unique(array_keys($catalog)));

    foreach ($catalog as $hook => ['model' => $model]) {
        expect(class_exists($model))->toBeTrue("Hook [{$hook}]'s model class [{$model}] does not exist.");
    }
});

test('an unknown before-save hook name is refused with the list of valid ones', function () {
    expect(fn () => app(PluginManager::class)->onBeforeSave('issue.exploded', fn () => null))
        ->toThrow(InvalidArgumentException::class, 'issue.before_save');
});

test('a plugin can change an issue\'s attributes before it is saved', function () {
    app(PluginManager::class)->onBeforeSave('issue.before_save', function (BeforeSaveContext $context) {
        $context->model->subject = strtoupper($context->model->subject);
    });

    $project = Project::factory()->create();
    $issue = app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'lowercase subject',
    ], User::factory()->create());

    expect($issue->fresh()->subject)->toBe('LOWERCASE SUBJECT');
});

test('a plugin can veto saving an issue, and the issue is never persisted', function () {
    app(PluginManager::class)->onBeforeSave('issue.before_save', function (BeforeSaveContext $context) {
        if (str_contains((string) $context->model->subject, 'forbidden')) {
            $context->fail('That subject is not allowed.');
        }
    });

    $project = Project::factory()->create();

    expect(fn () => app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'a forbidden subject',
    ], User::factory()->create()))->toThrow(ValidationException::class);

    expect(Issue::where('subject', 'a forbidden subject')->exists())->toBeFalse();
});

test('the before-save hook fires on update too, with isNew false', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Original',
    ]);

    $seenIsNew = null;
    app(PluginManager::class)->onBeforeSave('issue.before_save', function (BeforeSaveContext $context) use (&$seenIsNew) {
        $seenIsNew = $context->isNew;
    });

    app(IssueService::class)->update($issue, ['subject' => 'Changed'], $issue->author);

    expect($seenIsNew)->toBeFalse()
        ->and($issue->fresh()->subject)->toBe('Changed');
});

test('multiple listeners run in registration order, and a later one still runs after an earlier one fails', function () {
    $order = [];
    app(PluginManager::class)->onBeforeSave('issue.before_save', function (BeforeSaveContext $context) use (&$order) {
        $order[] = 'first';
        $context->fail('first failed it');
    });
    app(PluginManager::class)->onBeforeSave('issue.before_save', function (BeforeSaveContext $context) use (&$order) {
        $order[] = 'second';
    });

    $project = Project::factory()->create();

    expect(fn () => app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Order test',
    ], User::factory()->create()))->toThrow(ValidationException::class);

    expect($order)->toBe(['first', 'second']);
});

test('a plugin can change a time entry\'s attributes before it is saved, and veto one too', function () {
    app(PluginManager::class)->onBeforeSave('time_entry.before_save', function (BeforeSaveContext $context) {
        if ((float) $context->model->hours > 20) {
            $context->fail('Too many hours for one entry.');
        } else {
            $context->model->comments = 'Adjusted by plugin';
        }
    });

    $project = Project::factory()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $user = User::factory()->create();

    $entry = app(TimeEntryService::class)->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'spent_on' => today(),
        'hours' => 2,
        'comments' => 'Original',
    ]);

    expect($entry->fresh()->comments)->toBe('Adjusted by plugin');

    expect(fn () => app(TimeEntryService::class)->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'spent_on' => today(),
        'hours' => 25,
    ]))->toThrow(ValidationException::class);

    expect(TimeEntry::where('hours', 25)->exists())->toBeFalse();
});
