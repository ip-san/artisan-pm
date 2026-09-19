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

function includeTestMember(Project $project, array $permissions = ['view_issues', 'view_issue_watchers']): User
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

test('allowed_statuses lists the statuses the caller may move to plus the current one', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project, ['view_issues', 'edit_issues']);
    $tracker = Tracker::factory()->create();
    $open = IssueStatus::factory()->create(['name' => 'New', 'position' => 1]);
    $done = IssueStatus::factory()->create(['name' => 'Done', 'is_closed' => true, 'position' => 2]);
    $unreachable = IssueStatus::factory()->create(['name' => 'Unreachable', 'position' => 3]);
    $role = Role::query()->latest('id')->firstOrFail();
    App\Models\WorkflowTransition::create(['tracker_id' => $tracker->id, 'role_id' => $role->id, 'old_status_id' => $open->id, 'new_status_id' => $done->id, 'author' => false, 'assignee' => false]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $open->id, 'priority_id' => Enumeration::factory()->create()->id]);

    Passport::actingAs($user);

    $statuses = $this->getJson("/api/v1/issues/{$issue->id}?include=allowed_statuses")->assertOk()->json('data.allowed_statuses');

    expect(collect($statuses)->pluck('name')->all())->toBe(['New', 'Done'])
        ->and($statuses[1])->toBe(['id' => $done->id, 'name' => 'Done', 'is_closed' => true])
        ->and(collect($statuses)->pluck('name'))->not->toContain($unreachable->name);
});

test('allowed_statuses is absent unless requested', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}")->assertOk()->assertJsonMissingPath('data.allowed_statuses');
});

test('changesets lists linked commits the caller may view and hides other projects\' commits', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = includeTestMember($project, ['view_issues', 'view_changesets']);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $visible = App\Models\Changeset::factory()->for(App\Models\Repository::factory()->for($project))->create(['revision' => 'abc123', 'comments' => 'Fix the thing', 'committed_on' => now()->subDay()]);
    $hidden = App\Models\Changeset::factory()->for(App\Models\Repository::factory()->for($otherProject))->create(['revision' => 'def456']);
    $issue->changesets()->attach([$visible->id, $hidden->id]);

    Passport::actingAs($user);

    $changesets = $this->getJson("/api/v1/issues/{$issue->id}?include=changesets")->assertOk()->json('data.changesets');

    expect($changesets)->toHaveCount(1)
        ->and($changesets[0]['revision'])->toBe('abc123')
        ->and($changesets[0]['comments'])->toBe('Fix the thing')
        ->and($changesets[0])->toHaveKeys(['committer', 'committed_on']);
});

test('changesets is empty for a caller without view_changesets', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $changeset = App\Models\Changeset::factory()->for(App\Models\Repository::factory()->for($project))->create();
    $issue->changesets()->attach($changeset);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$issue->id}?include=changesets")->assertOk()->assertJsonPath('data.changesets', []);
});

test('children nest recursively and leave out subtasks the caller cannot see', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $author = User::factory()->create();
    $root = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $child = Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $root->id, 'subject' => 'Child']);
    $grandchild = Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $child->id, 'subject' => 'Grandchild']);
    $greatGrandchild = Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $grandchild->id, 'subject' => 'Great grandchild']);
    Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $root->id, 'subject' => 'Secret child', 'is_private' => true, 'author_id' => $author->id]);

    $role = Role::query()->latest('id')->firstOrFail();
    $role->update(['issues_visibility' => 'default']);

    Passport::actingAs($user);

    $children = $this->getJson("/api/v1/issues/{$root->id}?include=children")->assertOk()->json('data.children');

    expect(collect($children)->pluck('subject')->all())->toBe(['Child'])
        ->and($children[0]['children'][0]['subject'])->toBe('Grandchild')
        ->and($children[0]['children'][0]['children'][0]['id'])->toBe($greatGrandchild->id)
        ->and($children[0]['children'][0]['children'][0])->not->toHaveKey('children');
});

test('a leaf issue reports an empty children list and the nested load stays query-flat', function () {
    $project = Project::factory()->create();
    $user = includeTestMember($project);
    $root = Issue::factory()->for($project)->create(includeTestIssueDefaults());
    $parent = $root;
    foreach (range(1, 6) as $i) {
        $parent = Issue::factory()->for($project)->create([...includeTestIssueDefaults(), 'parent_id' => $parent->id]);
    }

    Passport::actingAs($user);

    $this->getJson("/api/v1/issues/{$parent->id}?include=children")->assertOk()->assertJsonPath('data.children', []);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->getJson("/api/v1/issues/{$root->id}?include=children")->assertOk();

    expect($queries)->toBeLessThan(60);
});
