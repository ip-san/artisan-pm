<?php

use App\Enums\IssueRelationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int, author_id: int}
 */
function includeTestIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
    ];
}

function includeTestMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('without ?include=, none of the optional keys appear in the response', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/issues/{$issue->id}");

    $response->assertOk()
        ->assertJsonMissingPath('data.journals')
        ->assertJsonMissingPath('data.relations')
        ->assertJsonMissingPath('data.attachments')
        ->assertJsonMissingPath('data.children')
        ->assertJsonMissingPath('data.watchers');
});

test('?include=journals returns the issue\'s journal entries', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->journals()->create(['user_id' => $user->id, 'notes' => 'A public comment']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=journals")
        ->assertOk()
        ->assertJsonPath('data.journals.0.notes', 'A public comment')
        ->assertJsonPath('data.journals.0.user.id', $user->id);
});

test('a private journal note is hidden from a user without view_private_notes, except from its own author', function () {
    $project = Project::factory()->create();
    $author = includeTestMember($project);
    $viewer = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->journals()->create(['user_id' => $author->id, 'notes' => 'Secret note', 'private_notes' => true]);

    Passport::actingAs($viewer);
    $this->getJson("/api/v1/issues/{$issue->id}?include=journals")
        ->assertOk()
        ->assertJsonPath('data.journals', []);

    Passport::actingAs($author);
    $this->getJson("/api/v1/issues/{$issue->id}?include=journals")
        ->assertOk()
        ->assertJsonPath('data.journals.0.notes', 'Secret note');
});

test('a private journal note is visible to a user with view_private_notes', function () {
    $project = Project::factory()->create();
    $author = includeTestMember($project);
    $privileged = includeTestMember($project, ['view_issues', 'view_private_notes']);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->journals()->create(['user_id' => $author->id, 'notes' => 'Secret note', 'private_notes' => true]);

    Passport::actingAs($privileged);

    $this->getJson("/api/v1/issues/{$issue->id}?include=journals")
        ->assertOk()
        ->assertJsonPath('data.journals.0.notes', 'Secret note');
});

test('?include=relations returns both directions, excluding a relation to an issue the caller cannot view', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $visibleOther = Issue::factory()->for($project)->create(includeTestIssueDefaults());

    $privateProject = Project::factory()->private()->create();
    $hiddenOther = Issue::factory()->for($privateProject)->create(includeTestIssueDefaults());

    IssueRelation::factory()->create([
        'issue_from_id' => $issue->id,
        'issue_to_id' => $visibleOther->id,
        'relation_type' => IssueRelationType::Relates->value,
    ]);
    IssueRelation::factory()->create([
        'issue_from_id' => $hiddenOther->id,
        'issue_to_id' => $issue->id,
        'relation_type' => IssueRelationType::Blocks->value,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/issues/{$issue->id}?include=relations");

    $response->assertOk();
    $relations = $response->json('data.relations');
    expect($relations)->toHaveCount(1)
        ->and($relations[0]['issue_to_id'])->toBe($visibleOther->id);
});

test('?include=attachments returns attachment metadata', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=attachments")
        ->assertOk()
        ->assertJsonPath('data.attachments.0.filename', 'notes.txt');
});

test('?include=children returns direct children only', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $child = Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $issue->id, 'subject' => 'Child issue']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=children")
        ->assertOk()
        ->assertJsonPath('data.children.0.id', $child->id)
        ->assertJsonPath('data.children.0.subject', 'Child issue');
});

test('?include=watchers returns the watcher list', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->watchers()->create(['user_id' => $user->id]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=watchers")
        ->assertOk()
        ->assertJsonPath('data.watchers.0.id', $user->id)
        ->assertJsonPath('data.watchers.0.name', $user->name);
});

test('multiple includes can be combined in one comma-separated request', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $issue->journals()->create(['user_id' => $user->id, 'notes' => 'Comment']);
    $issue->watchers()->create(['user_id' => $user->id]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=journals,watchers")
        ->assertOk()
        ->assertJsonPath('data.journals.0.notes', 'Comment')
        ->assertJsonPath('data.watchers.0.id', $user->id)
        ->assertJsonMissingPath('data.relations');
});

test('?include=relations on the index endpoint returns each issue\'s relations', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $other = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    IssueRelation::factory()->create([
        'issue_from_id' => $issue->id,
        'issue_to_id' => $other->id,
        'relation_type' => IssueRelationType::Relates->value,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/issues?include=relations");

    $response->assertOk();
    $matching = collect($response->json('data'))->firstWhere('id', $issue->id);
    expect($matching['relations'])->toHaveCount(1)
        ->and($matching['relations'][0]['issue_to_id'])->toBe($other->id);
});

test('an unrecognized include key is silently ignored', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=not_a_real_key")
        ->assertOk()
        ->assertJsonMissingPath('data.not_a_real_key');
});
