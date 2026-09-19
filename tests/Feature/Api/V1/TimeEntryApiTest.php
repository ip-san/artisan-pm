<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
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

test('a member without log_time_for_other_users cannot log time on behalf of another user', function () {
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

test('a member with log_time_for_other_users can log time on behalf of another user', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['log_time', 'log_time_for_other_users']);
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
    $user = apiTimeEntryMember($project, ['log_time', 'edit_own_time_entries']);
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
    $user = apiTimeEntryMember($project, ['log_time', 'edit_own_time_entries']);
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

function timeEntryApiIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

function timeEntryApiIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

test('the global index lists entries across projects, each project\'s visibility tier applied', function () {
    $open = Project::factory()->create();
    $restricted = Project::factory()->create();
    $noAccess = Project::factory()->create();
    $user = apiTimeEntryMember($open, ['view_time_entries']);
    Member::factory()->for($restricted)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'own'])
    );
    $inOpen = TimeEntry::factory()->for($open)->create();
    $ownRestricted = TimeEntry::factory()->for($restricted)->for($user)->create();
    $foreignRestricted = TimeEntry::factory()->for($restricted)->create();
    $inNoAccess = TimeEntry::factory()->for($noAccess)->create();

    Passport::actingAs($user);

    $ids = timeEntryApiIds($this->getJson('/api/v1/time_entries')->assertOk());

    expect($ids)->toContain($inOpen->id, $ownRestricted->id)
        ->not->toContain($foreignRestricted->id)
        ->not->toContain($inNoAccess->id);
});

test('the global index needs authentication', function () {
    $this->getJson('/api/v1/time_entries')->assertUnauthorized();
});

test('the global and project indexes filter by user, issue, activity and date range', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_time_entries']);
    $issue = timeEntryApiIssue($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);
    $mine = TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $issue->id, 'activity_id' => $activity->id, 'spent_on' => '2026-03-10']);
    $old = TimeEntry::factory()->for($project)->create(['spent_on' => '2025-01-01']);
    $late = TimeEntry::factory()->for($project)->create(['spent_on' => '2026-12-31']);

    Passport::actingAs($user);

    expect(timeEntryApiIds($this->getJson('/api/v1/time_entries?user_id=me')))->toBe([$mine->id])
        ->and(timeEntryApiIds($this->getJson("/api/v1/time_entries?issue_id={$issue->id}")))->toBe([$mine->id])
        ->and(timeEntryApiIds($this->getJson("/api/v1/time_entries?activity_id={$activity->id}")))->toBe([$mine->id])
        ->and(timeEntryApiIds($this->getJson('/api/v1/time_entries?from=2026-01-01&to=2026-06-30')))->toBe([$mine->id])
        ->and(timeEntryApiIds($this->getJson('/api/v1/time_entries?from=2026-06-01')))->toBe([$late->id])
        ->and(timeEntryApiIds($this->getJson("/api/v1/projects/{$project->id}/time_entries?to=2025-12-31")))->toBe([$old->id])
        ->and(timeEntryApiIds($this->getJson('/api/v1/time_entries?from=not-a-date&user_id=abc')))->toHaveCount(3);
});

test('an issue\'s time entries can be listed under the issue', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_issues', 'view_time_entries']);
    $issue = timeEntryApiIssue($project);
    $other = timeEntryApiIssue($project);
    $onIssue = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $other->id]);

    Passport::actingAs($user);

    expect(timeEntryApiIds($this->getJson("/api/v1/issues/{$issue->id}/time_entries")->assertOk()))->toBe([$onIssue->id]);
});

test('listing under an issue respects the own-entries tier and requires seeing both the issue and time entries', function () {
    $project = Project::factory()->create();
    $ownOnly = apiTimeEntryMember($project, ['view_issues', 'view_time_entries'], 'own');
    $noTime = apiTimeEntryMember($project, ['view_issues']);
    $outsider = User::factory()->create();
    $issue = timeEntryApiIssue($project);
    $mine = TimeEntry::factory()->for($project)->for($ownOnly)->create(['issue_id' => $issue->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    Passport::actingAs($ownOnly);
    expect(timeEntryApiIds($this->getJson("/api/v1/issues/{$issue->id}/time_entries")))->toBe([$mine->id]);

    Passport::actingAs($noTime);
    $this->getJson("/api/v1/issues/{$issue->id}/time_entries")->assertForbidden();

    Passport::actingAs($outsider);
    $this->getJson("/api/v1/issues/{$issue->id}/time_entries")->assertForbidden();
});

test('time can be logged against an issue through the issue route', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_issues', 'log_time']);
    $issue = timeEntryApiIssue($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/issues/{$issue->id}/time_entries", [
        'activity_id' => $activity->id,
        'hours' => 1.5,
        'spent_on' => '2026-05-05',
    ])->assertCreated();

    $entry = TimeEntry::findOrFail($response->json('data.id'));
    expect($entry->issue_id)->toBe($issue->id)
        ->and($entry->project_id)->toBe($project->id)
        ->and($entry->user_id)->toBe($user->id);
});

test('the issue route ignores a smuggled issue_id and project, and still needs log_time and issue access', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_issues', 'log_time']);
    $viewer = apiTimeEntryMember($project, ['view_issues']);
    $outsider = User::factory()->create();
    $issue = timeEntryApiIssue($project);
    $foreign = timeEntryApiIssue($otherProject);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);
    $payload = ['activity_id' => $activity->id, 'hours' => 1, 'spent_on' => '2026-05-05'];

    Passport::actingAs($user);
    $id = $this->postJson("/api/v1/issues/{$issue->id}/time_entries", [...$payload, 'issue_id' => $foreign->id, 'project_id' => $otherProject->id])->assertCreated()->json('data.id');
    expect(TimeEntry::findOrFail($id)->issue_id)->toBe($issue->id)
        ->and(TimeEntry::findOrFail($id)->project_id)->toBe($project->id);

    Passport::actingAs($viewer);
    $this->postJson("/api/v1/issues/{$issue->id}/time_entries", $payload)->assertForbidden();

    Passport::actingAs($outsider);
    $this->postJson("/api/v1/issues/{$issue->id}/time_entries", $payload)->assertForbidden();
});

test('a user without log_time_for_other_users cannot log time for someone else through the issue route', function () {
    $project = Project::factory()->create();
    $user = apiTimeEntryMember($project, ['view_issues', 'log_time']);
    $someoneElse = User::factory()->create();
    $issue = timeEntryApiIssue($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity]);

    Passport::actingAs($user);

    $id = $this->postJson("/api/v1/issues/{$issue->id}/time_entries", [
        'activity_id' => $activity->id, 'hours' => 1, 'spent_on' => '2026-05-05', 'user_id' => $someoneElse->id,
    ])->assertCreated()->json('data.id');

    expect(TimeEntry::findOrFail($id)->user_id)->toBe($user->id);
});
