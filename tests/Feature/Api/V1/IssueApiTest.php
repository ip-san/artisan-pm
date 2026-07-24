<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
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
