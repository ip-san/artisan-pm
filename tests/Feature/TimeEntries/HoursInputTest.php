<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Format\Hours;
use Laravel\Passport\Passport;
use Livewire\Livewire;

test('hours text is read the way Redmine reads it', function (string $text, ?float $hours) {
    expect(Hours::parse($text))->toBe($hours);
})->with([
    ['2', 2.0],
    ['1.5', 1.5],
    ['1,5', 1.5],
    ['1.5h', 1.5],
    [' 2 ', 2.0],
    ['1:30', 1.5],
    ['0:45', 0.75],
    ['1h30', 1.5],
    ['1h 30m', 1.5],
    ['2 hours', 2.0],
    ['1 hour 15 min', 1.25],
    ['90m', 1.5],
    ['45min', 0.75],
    ['2H', 2.0],
    ['abc', null],
    ['1:3x', null],
    ['', null],
]);

/**
 * @param  array<int, string>  $permissions
 */
function hoursInputMember(Project $project, array $permissions = ['log_time', 'view_time_entries', 'edit_time_entries', 'view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the time entry form accepts hours and minutes', function () {
    $project = Project::factory()->create();
    $user = hoursInputMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)
        ->set('hours', '1:30')
        ->set('spent_on', '2026-09-01')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) TimeEntry::sole()->hours)->toBe(1.5);
});

test('the time entry form still rejects text that is not hours', function () {
    $project = Project::factory()->create();
    $user = hoursInputMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)
        ->set('hours', 'soon')
        ->set('spent_on', '2026-09-01')
        ->call('save')
        ->assertHasErrors(['hours']);

    expect(TimeEntry::query()->count())->toBe(0);
});

test('the REST API reads hours text on time entries', function () {
    $project = Project::factory()->create();
    $user = hoursInputMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['activity_id' => $activity->id, 'hours' => '2h15', 'spent_on' => '2026-09-01'])
        ->assertCreated()
        ->assertJsonPath('data.hours', 2.25);

    $entry = TimeEntry::sole();
    $this->putJson("/api/v1/time_entries/{$entry->id}", ['hours' => '0:45'])->assertSuccessful();

    expect((float) $entry->fresh()->hours)->toBe(0.75);

    $this->putJson("/api/v1/time_entries/{$entry->id}", ['hours' => 'later'])->assertUnprocessable();
});
