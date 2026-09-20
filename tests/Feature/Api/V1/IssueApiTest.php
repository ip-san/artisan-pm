<?php

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
use Laravel\Passport\Passport;

function apiIssueMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int, author_id: int}
 */
function apiIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
    ];
}

test('creating an issue via the api requires add_issues permission', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'subject' => 'Should be forbidden',
    ])->assertForbidden();
});

test('a member with add_issues can create an issue via the api', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $status = IssueStatus::factory()->create();

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'Created via API',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.subject', 'Created via API')
        ->assertJsonPath('data.status_id', $status->id);

    $issue = Issue::where('subject', 'Created via API')->firstOrFail();
    expect($issue->author_id)->toBe($user->id);
});

test('an issue cannot be created with a tracker from another project', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $otherTracker = Tracker::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $otherTracker->id,
        'subject' => 'Cross-project tracker',
    ])->assertUnprocessable()->assertJsonValidationErrors(['tracker_id']);
});

test('updating an issue via the api records a journal entry', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create([...apiIssueDefaults(), 'subject' => 'Original']);

    Passport::actingAs($user);

    $response = $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Updated via API']);

    $response->assertOk()->assertJsonPath('data.subject', 'Updated via API');
    expect($issue->fresh()->journals()->count())->toBe(1);
});

test('changing status via the api is still governed by the workflow', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());
    $disallowedStatus = IssueStatus::factory()->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['status_id' => $disallowedStatus->id])
        ->assertForbidden();
});

test('a non-member cannot view an issue in a private project via the api', function () {
    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->getJson("/api/v1/issues/{$issue->id}")->assertForbidden();
});

test('a member with delete_issues can delete an issue via the api', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'delete_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}")->assertNoContent();

    expect(Issue::find($issue->id))->toBeNull();
});

test('deleting an issue via the api keeps its time entries detached unless told otherwise', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'delete_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}")->assertNoContent();

    expect($entry->fresh()->issue_id)->toBeNull();
});

test('the api delete accepts todo=destroy to remove the time entries too', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'delete_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=destroy")->assertNoContent();

    expect(TimeEntry::find($entry->id))->toBeNull();
});

test('the api delete can reassign time entries and rejects a bad target or todo without deleting', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'delete_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());
    $target = Issue::factory()->for($project)->create(apiIssueDefaults());
    $foreign = Issue::factory()->for(Project::factory()->create())->create(apiIssueDefaults());
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=reassign")->assertUnprocessable();
    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=reassign&reassign_to_id={$foreign->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=reassign&reassign_to_id={$issue->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=explode")->assertUnprocessable();
    expect(Issue::find($issue->id))->not->toBeNull();

    $this->deleteJson("/api/v1/issues/{$issue->id}?todo=reassign&reassign_to_id={$target->id}")->assertNoContent();

    expect($entry->fresh()->issue_id)->toBe($target->id)
        ->and(Issue::find($issue->id))->toBeNull();
});

test('deleting an issue via the api requires delete_issues permission', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}")->assertForbidden();

    expect(Issue::find($issue->id))->not->toBeNull();
});

test('creating an issue via the api attaches an uploaded file by token', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    $uploadResponse = $this->call('POST', '/api/v1/uploads?filename=notes.txt', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], 'hello world');
    $token = $uploadResponse->json('upload.token');

    $response = $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'With attachment',
        'uploads' => [['token' => $token, 'filename' => 'renamed.txt']],
    ]);

    $response->assertCreated();

    $issue = Issue::where('subject', 'With attachment')->firstOrFail();
    expect($issue->attachments())->toHaveCount(1)
        ->and($issue->attachments()->first()->file_name)->toBe('renamed.txt');
});

test('updating an issue via the api attaches an uploaded file by token and journals it', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $uploadResponse = $this->call('POST', '/api/v1/uploads?filename=notes.txt', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], 'hello world');
    $token = $uploadResponse->json('upload.token');

    $this->putJson("/api/v1/issues/{$issue->id}", [
        'uploads' => [['token' => $token]],
    ])->assertOk();

    expect($issue->attachments())->toHaveCount(1)
        ->and($issue->journals()->whereHas('details', fn ($q) => $q->where('property', 'attachment'))->exists())->toBeTrue();
});

test('an unknown upload token is silently ignored rather than failing the request', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'With bad token',
        'uploads' => [['token' => '999999.not-a-real-uuid']],
    ]);

    $response->assertCreated();

    $issue = Issue::where('subject', 'With bad token')->firstOrFail();
    expect($issue->attachments())->toHaveCount(0);
});

test('a filename override at attach time cannot bypass the extension deny list', function () {
    Setting::set('attachment_extensions_denied', 'exe');

    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    // The upload itself is fine — "notes.txt" isn't denied — so the
    // extension check at upload time has nothing to catch here.
    $uploadResponse = test()->call('POST', '/api/v1/uploads?filename=notes.txt', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], 'hello world');
    $token = $uploadResponse->json('upload.token');

    $response = $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'Rename bypass attempt',
        'uploads' => [['token' => $token, 'filename' => 'malware.exe']],
    ]);

    $response->assertCreated();

    $issue = Issue::where('subject', 'Rename bypass attempt')->firstOrFail();
    $attachment = $issue->attachments()->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->file_name)->toBe('notes.txt');
});

test('the issue payload carries the lock_version', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}")->assertOk()->assertJsonPath('data.lock_version', $issue->lock_version);
});

test('an api update with the current lock_version succeeds and bumps it', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Renamed', 'lock_version' => $issue->lock_version])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Renamed')
        ->assertJsonPath('data.lock_version', $issue->lock_version + 1);
});

test('an api update with a stale lock_version is a 409 that changes nothing', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create([...apiIssueDefaults(), 'subject' => 'Original']);
    $staleVersion = $issue->lock_version;

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'First writer'])->assertOk();

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Second writer', 'lock_version' => $staleVersion, 'notes' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('lock_version', $staleVersion + 1)
        ->assertJsonStructure(['message', 'errors' => ['lock_version']]);

    expect($issue->fresh()->subject)->toBe('First writer')
        ->and($issue->fresh()->lock_version)->toBe($staleVersion + 1);
});

test('an api update without lock_version keeps last-write-wins', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'One'])->assertOk();
    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Two'])->assertOk();

    expect($issue->fresh()->subject)->toBe('Two');
});

test('a malformed lock_version is rejected as a validation error', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['lock_version' => 'abc'])->assertUnprocessable();
    $this->putJson("/api/v1/issues/{$issue->id}", ['lock_version' => -1])->assertUnprocessable();
});

test('a stale lock_version cannot be used to probe an issue the caller may not edit', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create(apiIssueDefaults());

    Passport::actingAs($user);

    $this->putJson("/api/v1/issues/{$issue->id}", ['lock_version' => 999])->assertForbidden();
});

test('an API-created issue only gets a start date when the API and mail switch is on', function () {
    $project = Project::factory()->create();
    $user = apiIssueMember($project, ['view_issues', 'add_issues']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();
    Passport::actingAs($user);

    $create = fn (array $extra = []) => $this->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id, 'priority_id' => $priority->id, 'subject' => 'Start date', ...$extra,
    ])->assertCreated();

    $create()->assertJsonPath('data.start_date', null);

    App\Models\Setting::set('default_issue_start_date_for_api_and_mail', true);
    $create()->assertJsonPath('data.start_date', today()->toDateString());
    $create(['start_date' => '2026-02-03'])->assertJsonPath('data.start_date', '2026-02-03');
});
