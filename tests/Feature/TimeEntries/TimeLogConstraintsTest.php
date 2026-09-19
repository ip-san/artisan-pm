<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function timeLogProject(): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'edit_issues', 'add_issues', 'log_time', 'view_time_entries', 'edit_time_entries', 'edit_own_time_entries']])
    );
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    return [$project, $user, $activity];
}

function timeLogIssue(Project $project, bool $closed = false): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create(['is_closed' => $closed])->id,
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function timeLogAttributes(Project $project, User $user, Enumeration $activity, array $overrides = []): array
{
    return [
        'project_id' => $project->id,
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'hours' => 1.5,
        'spent_on' => now()->toDateString(),
        'comments' => 'work',
        ...$overrides,
    ];
}

function timeLogErrors(callable $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('with default settings every entry Redmine accepts by default is accepted', function () {
    [$project, $user, $activity] = timeLogProject();
    $closed = timeLogIssue($project, closed: true);

    $service = app(TimeEntryService::class);
    $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 0, 'issue_id' => $closed->id, 'comments' => '', 'spent_on' => now()->addDays(3)->toDateString()]));

    expect(TimeEntry::query()->count())->toBe(1);
});

test('required fields reject an entry without an issue or without comments', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_required_fields', ['issue_id', 'comments']);
    $issue = timeLogIssue($project);
    $service = app(TimeEntryService::class);

    $errors = timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['comments' => ''])));
    expect($errors)->toHaveKeys(['issue_id', 'comments']);

    $service->create(timeLogAttributes($project, $user, $activity, ['issue_id' => $issue->id]));
    expect(TimeEntry::query()->count())->toBe(1);
});

test('required fields also apply when an existing entry is edited', function () {
    [$project, $user, $activity] = timeLogProject();
    $service = app(TimeEntryService::class);
    $entry = $service->create(timeLogAttributes($project, $user, $activity));
    Setting::set('timelog_required_fields', ['issue_id']);

    expect(timeLogErrors(fn () => $service->update($entry, ['comments' => 'changed'])))->toHaveKey('issue_id');
});

test('zero hours are rejected when the setting is off, but an untouched zero entry stays editable', function () {
    [$project, $user, $activity] = timeLogProject();
    $service = app(TimeEntryService::class);
    $zero = $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 0]));
    Setting::set('timelog_accept_0_hours', false);

    expect(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 0]))))->toHaveKey('hours')
        ->and(timeLogErrors(fn () => $service->update($zero, ['comments' => 'still fine'])))->toBe([])
        ->and(timeLogErrors(fn () => $service->update($zero, ['hours' => 0.0])))->toBe([]);
});

test('the daily maximum counts what the same user already logged that day', function () {
    [$project, $user, $activity] = timeLogProject();
    $service = app(TimeEntryService::class);
    Setting::set('timelog_max_hours_per_day', 8);
    $entry = $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 6]));

    expect(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 2.5]))))->toHaveKey('hours')
        ->and(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 2]))))->toBe([])
        ->and(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 5, 'spent_on' => now()->subDay()->toDateString()]))))->toBe([])
        ->and(timeLogErrors(fn () => $service->create(timeLogAttributes($project, User::factory()->create(), $activity, ['hours' => 8]))))->toBe([])
        ->and(timeLogErrors(fn () => $service->update($entry, ['hours' => 5])))->toBe([]);

    Setting::set('timelog_max_hours_per_day', 0);
    expect(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['hours' => 500]))))->toBe([]);
});

test('future dates are rejected when the setting is off', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_accept_future_dates', false);
    $service = app(TimeEntryService::class);

    expect(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['spent_on' => now()->addDay()->toDateString()]))))->toHaveKey('spent_on')
        ->and(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['spent_on' => now()->toDateString()]))))->toBe([]);
});

test('closed issues are rejected when the setting is off', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_accept_closed_issues', false);
    $closed = timeLogIssue($project, closed: true);
    $open = timeLogIssue($project);
    $service = app(TimeEntryService::class);

    expect(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['issue_id' => $closed->id]))))->toHaveKey('issue_id')
        ->and(timeLogErrors(fn () => $service->create(timeLogAttributes($project, $user, $activity, ['issue_id' => $open->id]))))->toBe([]);
});

test('the web form shows the rejection on the field', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_required_fields', ['comments']);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)
        ->set('hours', '2')
        ->set('spent_on', now()->toDateString())
        ->set('comments', '')
        ->call('save')
        ->assertHasErrors(['comments']);

    expect(TimeEntry::query()->count())->toBe(0);
});

test('the API answers 422 when a setting rejects the entry', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_max_hours_per_day', 4);
    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['hours' => 3, 'activity_id' => $activity->id])->assertCreated();
    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['hours' => 2, 'activity_id' => $activity->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hours']);
    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['hours' => 0, 'activity_id' => $activity->id])->assertCreated();
});

test('the issue form leaves the issue unchanged when the logged time is rejected', function () {
    [$project, $user, $activity] = timeLogProject();
    Setting::set('timelog_max_hours_per_day', 2);
    $issue = timeLogIssue($project, closed: false);
    $original = $issue->subject;

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', 'Renamed')
        ->set('logTimeHours', '5')
        ->set('logTimeActivityId', $activity->id)
        ->call('save');

    $component->assertHasErrors(['logTimeHours']);
    expect($issue->fresh()->subject)->toBe($original)
        ->and(TimeEntry::query()->count())->toBe(0);
});

test('the settings form saves the time tracking options', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('timelog_required_fields', ['issue_id'])
        ->set('timelog_accept_0_hours', false)
        ->set('timelog_max_hours_per_day', 7.5)
        ->set('timelog_accept_future_dates', false)
        ->set('timelog_accept_closed_issues', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('timelog_required_fields'))->toBe(['issue_id'])
        ->and(Setting::get('timelog_max_hours_per_day'))->toBe(7.5)
        ->and(Setting::get('timelog_accept_0_hours'))->toBeFalse();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('timelog_required_fields', ['bogus'])
        ->call('save')
        ->assertHasErrors(['timelog_required_fields.0']);
});
