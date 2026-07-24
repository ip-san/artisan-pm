<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Passport\Passport;

function apiTimeEntryMember(Project $project, array $permissions, string $timeEntriesVisibility = 'all'): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'time_entries_visibility' => $timeEntriesVisibility]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/time_entries")->assertUnauthorized();
});

test('a member with view_time_entries can list a project\'s time entries', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_time_entries']);
    $entry = TimeEntry::factory()->for($project)->create();

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/time_entries");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($entry->id);
});

test('a member without view_time_entries cannot list time entries', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_issues']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/time_entries")->assertForbidden();
});

test('a member restricted to own time entries only sees their own in the index', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_time_entries'], 'own');
    $ownEntry = TimeEntry::factory()->for($project)->for($user)->create();
    $otherEntry = TimeEntry::factory()->for($project)->create();

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/time_entries");

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownEntry->id)->not->toContain($otherEntry->id);
});

test('a member restricted to own time entries cannot show another member\'s entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_time_entries'], 'own');
    $otherEntry = TimeEntry::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/time_entries/{$otherEntry->id}")->assertForbidden();
});

test('a member with log_time can create a time entry for themselves', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'activity_id' => $activity->id,
        'hours' => 2.5,
        'spent_on' => '2026-07-20',
        'comments' => 'Worked on the API',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.hours', 2.5);
});

test('a member without log_time cannot create a time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_time_entries']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'activity_id' => $activity->id,
        'hours' => 1,
    ])->assertForbidden();
});

test('creating a time entry with an activity not available to the project is rejected', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $inactiveActivity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity, 'active' => false]);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'activity_id' => $inactiveActivity->id,
        'hours' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors(['activity_id']);
});

test('a member without edit_time_entries cannot log time on behalf of another user', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $other = User::factory()->create();
    Member::factory()->for($project)->for($other)->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'user_id' => $other->id,
        'activity_id' => $activity->id,
        'hours' => 1,
    ]);

    $response->assertCreated()->assertJsonPath('data.user_id', $user->id);
});

test('a member with edit_time_entries can log time on behalf of another user', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time', 'edit_time_entries']);
    $other = User::factory()->create();
    Member::factory()->for($project)->for($other)->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'user_id' => $other->id,
        'activity_id' => $activity->id,
        'hours' => 1,
    ]);

    $response->assertCreated()->assertJsonPath('data.user_id', $other->id);
});

test('comments longer than 1024 characters are rejected', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/time_entries", [
        'activity_id' => $activity->id,
        'hours' => 1,
        'comments' => str_repeat('a', 1025),
    ])->assertUnprocessable()->assertJsonValidationErrors(['comments']);
});

test('the owner can update their own time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $entry = TimeEntry::factory()->for($project)->for($user)->create(['hours' => 1]);

    Passport::actingAs($user);

    $this->putJson("/api/v1/time_entries/{$entry->id}", ['hours' => 3.5])
        ->assertOk()
        ->assertJsonPath('data.hours', 3.5);
});

test('a member without edit_time_entries cannot update another member\'s time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $entry = TimeEntry::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/time_entries/{$entry->id}", ['hours' => 3])->assertForbidden();
});

test('a member with edit_time_entries can update another member\'s time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['edit_time_entries']);
    $entry = TimeEntry::factory()->for($project)->create(['hours' => 1]);

    Passport::actingAs($user);

    $this->putJson("/api/v1/time_entries/{$entry->id}", ['hours' => 4.25])
        ->assertOk()
        ->assertJsonPath('data.hours', 4.25);
});

test('the owner can delete their own time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $entry = TimeEntry::factory()->for($project)->for($user)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/time_entries/{$entry->id}")->assertNoContent();

    expect(TimeEntry::find($entry->id))->toBeNull();
});

test('a member without edit_time_entries cannot delete another member\'s time entry', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time']);
    $entry = TimeEntry::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/time_entries/{$entry->id}")->assertForbidden();

    expect(TimeEntry::find($entry->id))->not->toBeNull();
});
