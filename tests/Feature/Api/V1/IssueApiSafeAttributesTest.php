<?php

use App\Enums\UserStatus;
use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\WorkflowTransition;
use Laravel\Passport\Passport;

/**
 * A project with one tracker, a default priority and a first status, plus a
 * member holding $permissions.
 *
 * @param  array<int, string>  $permissions
 * @return array{project: Project, tracker: Tracker, priority: Enumeration, status: IssueStatus, user: User, role: Role}
 */
function safeAttributesSetup(array $permissions, array $roleAttributes = []): array
{
    $status = IssueStatus::factory()->create(['position' => 1]);
    $tracker = Tracker::factory()->create(['default_status_id' => $status->id]);
    $project = Project::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues', ...$permissions], ...$roleAttributes]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return compact('project', 'tracker', 'priority', 'status', 'user', 'role');
}

function safeAttributesMember(Project $project, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Member::factory()->for($project)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => true]));

    return $user;
}

/**
 * @return array<string, mixed>
 */
function safeAttributesPayload(array $setup, array $extra = []): array
{
    return ['tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'Via API', ...$extra];
}

function safeAttributesIssue(array $setup, array $attributes = []): Issue
{
    return Issue::factory()->for($setup['project'])->create([
        'tracker_id' => $setup['tracker']->id,
        'status_id' => $setup['status']->id,
        'priority_id' => $setup['priority']->id,
        ...$attributes,
    ]);
}

// --- category_id ---------------------------------------------------------

test('an api-created issue takes a category of its project and that category\'s default assignee', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $assignee = safeAttributesMember($setup['project']);
    $category = IssueCategory::factory()->for($setup['project'])->create(['assigned_to_id' => $assignee->id]);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['category_id' => $category->id]))
        ->assertCreated()
        ->assertJsonPath('data.category_id', $category->id)
        ->assertJsonPath('data.assigned_to_id', $assignee->id);
});

test('the api rejects a category of another project on create and update', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues']);
    $foreign = IssueCategory::factory()->create();
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['category_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['category_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
});

test('the api changes and clears an issue\'s category and journals it', function () {
    $setup = safeAttributesSetup(['edit_issues']);
    $category = IssueCategory::factory()->for($setup['project'])->create();
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['category_id' => $category->id])
        ->assertOk()->assertJsonPath('data.category_id', $category->id);
    expect($issue->journals()->first()->details()->where('prop_key', 'category_id')->exists())->toBeTrue();

    $this->putJson("/api/v1/issues/{$issue->id}", ['category_id' => null])->assertOk();
    expect($issue->fresh()->category_id)->toBeNull();
});

// --- estimated_hours -----------------------------------------------------

test('the api sets estimated hours and rejects a negative estimate', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues']);
    Passport::actingAs($setup['user']);

    $id = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['estimated_hours' => 2.5]))
        ->assertCreated()->assertJsonPath('data.estimated_hours', 2.5)->json('data.id');

    $this->putJson("/api/v1/issues/{$id}", ['estimated_hours' => 4])->assertOk()->assertJsonPath('data.estimated_hours', 4);
    $this->putJson("/api/v1/issues/{$id}", ['estimated_hours' => -1])->assertUnprocessable()->assertJsonValidationErrors(['estimated_hours']);
    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['estimated_hours' => 'many']))
        ->assertUnprocessable()->assertJsonValidationErrors(['estimated_hours']);
});

// --- is_private ----------------------------------------------------------

test('is_private is taken from a caller holding set_issues_private', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues', 'set_issues_private']);
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['is_private' => true]))
        ->assertCreated()->assertJsonPath('data.is_private', true);
    $this->putJson("/api/v1/issues/{$issue->id}", ['is_private' => true])->assertOk()->assertJsonPath('data.is_private', true);
});

test('is_private is ignored for a caller without set_issues_private', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues']);
    $issue = safeAttributesIssue($setup, ['is_private' => true]);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['is_private' => true]))
        ->assertCreated()->assertJsonPath('data.is_private', false);
    $this->putJson("/api/v1/issues/{$issue->id}", ['is_private' => false])->assertOk();

    expect($issue->fresh()->is_private)->toBeTrue();
});

test('set_own_issues_private lets the api change the flag on the caller\'s own issues only', function () {
    $setup = safeAttributesSetup(['edit_issues', 'set_own_issues_private']);
    $own = safeAttributesIssue($setup, ['author_id' => $setup['user']->id]);
    $others = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$own->id}", ['is_private' => true])->assertOk();
    $this->putJson("/api/v1/issues/{$others->id}", ['is_private' => true])->assertOk();

    expect($own->fresh()->is_private)->toBeTrue()
        ->and($others->fresh()->is_private)->toBeFalse();
});

test('a tracker that is private by default makes an api-created issue private unless is_private is given', function () {
    $setup = safeAttributesSetup(['add_issues', 'set_issues_private']);
    $setup['tracker']->update(['private_by_default' => true]);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup))
        ->assertCreated()->assertJsonPath('data.is_private', true);
    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['is_private' => false]))
        ->assertCreated()->assertJsonPath('data.is_private', false);
});

test('private by default does not apply for a caller who may not set the flag', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $setup['tracker']->update(['private_by_default' => true]);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup))
        ->assertCreated()->assertJsonPath('data.is_private', false);
});

// --- parent_issue_id -----------------------------------------------------

test('parent_issue_id sets the parent for a caller holding manage_subtasks', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues', 'manage_subtasks']);
    $parent = safeAttributesIssue($setup);
    $other = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['parent_issue_id' => $parent->id]))
        ->assertCreated()->assertJsonPath('data.parent_id', $parent->id);

    $this->putJson("/api/v1/issues/{$other->id}", ['parent_issue_id' => $parent->id])->assertOk()->assertJsonPath('data.parent_id', $parent->id);
    $this->putJson("/api/v1/issues/{$other->id}", ['parent_issue_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
});

test('parent_issue_id is ignored without manage_subtasks', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues']);
    $parent = safeAttributesIssue($setup);
    $child = safeAttributesIssue($setup, ['parent_id' => $parent->id]);
    $other = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['parent_issue_id' => $parent->id]))
        ->assertCreated()->assertJsonPath('data.parent_id', null);
    $this->putJson("/api/v1/issues/{$other->id}", ['parent_issue_id' => $parent->id])->assertOk();
    $this->putJson("/api/v1/issues/{$child->id}", ['parent_issue_id' => null])->assertOk();

    expect($other->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($parent->id);
});

test('the api rejects a parent in another project, one the caller cannot see, or the issue\'s own descendant', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues', 'manage_subtasks'], ['issues_visibility' => 'default']);
    $foreign = Issue::factory()->create();
    $hidden = safeAttributesIssue($setup, ['is_private' => true]);
    $issue = safeAttributesIssue($setup);
    $child = safeAttributesIssue($setup, ['parent_id' => $issue->id]);
    Passport::actingAs($setup['user']);

    foreach ([$foreign->id, $hidden->id, 999999] as $parentId) {
        $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['parent_issue_id' => $parentId]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_issue_id']);
    }

    $this->putJson("/api/v1/issues/{$issue->id}", ['parent_issue_id' => $child->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_issue_id']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['parent_issue_id' => $issue->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_issue_id']);

    expect(Issue::query()->where('subject', 'Via API')->exists())->toBeFalse()
        ->and($issue->fresh()->parent_id)->toBeNull();
});

// --- watcher_user_ids ----------------------------------------------------

test('watcher_user_ids adds active members as watchers for a caller holding add_issue_watchers', function () {
    $setup = safeAttributesSetup(['add_issues', 'add_issue_watchers']);
    $watcher = safeAttributesMember($setup['project']);
    $locked = safeAttributesMember($setup['project'], ['status' => UserStatus::Locked]);
    Passport::actingAs($setup['user']);

    $id = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['watcher_user_ids' => [$watcher->id, $locked->id]]))
        ->assertCreated()->json('data.id');

    expect(Issue::find($id)->watchers()->pluck('user_id')->all())->toContain($watcher->id)->not->toContain($locked->id);
});

test('watcher_user_ids is ignored without add_issue_watchers and rejects non-members', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $watcher = safeAttributesMember($setup['project']);
    Passport::actingAs($setup['user']);

    $id = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['watcher_user_ids' => [$watcher->id]]))
        ->assertCreated()->json('data.id');
    expect(Issue::find($id)->watchers()->where('user_id', $watcher->id)->exists())->toBeFalse();

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['watcher_user_ids' => [User::factory()->create()->id]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['watcher_user_ids.0']);
});

test('watcher_user_ids is ignored on update, as in Redmine', function () {
    $setup = safeAttributesSetup(['edit_issues', 'add_issue_watchers']);
    $watcher = safeAttributesMember($setup['project']);
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['watcher_user_ids' => [$watcher->id]])->assertOk();

    expect($issue->watchers()->where('user_id', $watcher->id)->exists())->toBeFalse();
});

// --- status_id on create --------------------------------------------------

test('an api-created issue starts in the tracker\'s default status, or a status the workflow allows', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $draft = IssueStatus::factory()->create(['position' => 2]);
    $closed = IssueStatus::factory()->create(['position' => 3]);
    $setup['tracker']->update(['default_status_id' => $draft->id]);
    foreach ([$setup['status'], $draft] as $allowed) {
        WorkflowTransition::query()->create(['tracker_id' => $setup['tracker']->id, 'role_id' => $setup['role']->id, 'old_status_id' => null, 'new_status_id' => $allowed->id, 'author' => false, 'assignee' => false]);
    }
    Passport::actingAs($setup['user']);

    $base = "/api/v1/projects/{$setup['project']->id}/issues";
    $this->postJson($base, safeAttributesPayload($setup))->assertCreated()->assertJsonPath('data.status_id', $draft->id);
    $this->postJson($base, safeAttributesPayload($setup, ['status_id' => $setup['status']->id]))->assertCreated()->assertJsonPath('data.status_id', $setup['status']->id);
    // A status the workflow doesn't allow falls back to the default, as in Redmine.
    $this->postJson($base, safeAttributesPayload($setup, ['status_id' => $closed->id]))->assertCreated()->assertJsonPath('data.status_id', $draft->id);
});

// --- fixed_version_id -----------------------------------------------------

test('the api accepts an open version shared with the project and rejects a closed one', function () {
    $setup = safeAttributesSetup(['add_issues', 'edit_issues']);
    $shared = Version::factory()->create(['sharing' => 'system']);
    $closed = Version::factory()->for($setup['project'])->create(['status' => VersionStatus::Closed]);
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['fixed_version_id' => $shared->id]))
        ->assertCreated()->assertJsonPath('data.fixed_version_id', $shared->id);
    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", safeAttributesPayload($setup, ['fixed_version_id' => $closed->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['fixed_version_id']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['fixed_version_id' => $closed->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['fixed_version_id']);
});

test('an issue keeps its current version through an api update after the version is closed', function () {
    $setup = safeAttributesSetup(['edit_issues']);
    $version = Version::factory()->for($setup['project'])->create();
    $issue = safeAttributesIssue($setup, ['fixed_version_id' => $version->id]);
    $version->update(['status' => VersionStatus::Closed]);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['fixed_version_id' => $version->id, 'subject' => 'Still here'])->assertOk();

    expect($issue->fresh()->fixed_version_id)->toBe($version->id);
});

// --- notes / private_notes -------------------------------------------------

test('notes sent with an api update are journaled with the changes', function () {
    $setup = safeAttributesSetup(['edit_issues']);
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Renamed', 'notes' => 'Why it changed'])->assertOk();

    $journal = $issue->journals()->sole();
    expect($journal->notes)->toBe('Why it changed')
        ->and($journal->private_notes)->toBeFalse()
        ->and($journal->details()->where('prop_key', 'subject')->exists())->toBeTrue();
});

test('private_notes is honored only for a caller holding set_notes_private', function () {
    $setup = safeAttributesSetup(['edit_issues', 'set_notes_private']);
    $issue = safeAttributesIssue($setup);
    $outsider = safeAttributesSetup(['edit_issues']);
    $otherIssue = safeAttributesIssue($outsider);

    Passport::actingAs($setup['user']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['notes' => 'Secret', 'private_notes' => true])->assertOk();

    Passport::actingAs($outsider['user']);
    $this->putJson("/api/v1/issues/{$otherIssue->id}", ['notes' => 'Not secret', 'private_notes' => true])->assertOk();

    expect($issue->journals()->sole()->private_notes)->toBeTrue()
        ->and($otherIssue->journals()->sole()->private_notes)->toBeFalse();
});

test('a caller who may only add notes can comment through the api but changes no field', function () {
    $setup = safeAttributesSetup(['add_issue_notes']);
    $issue = safeAttributesIssue($setup, ['subject' => 'Original']);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Hijacked', 'notes' => 'Just a comment'])->assertOk();

    $journal = $issue->journals()->sole();
    expect($issue->fresh()->subject)->toBe('Original')
        ->and($journal->notes)->toBe('Just a comment')
        ->and($journal->details()->count())->toBe(0);
});

test('a caller who may neither edit nor add notes cannot update through the api', function () {
    $setup = safeAttributesSetup([]);
    $issue = safeAttributesIssue($setup);
    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['notes' => 'Nope'])->assertForbidden();

    expect($issue->journals()->exists())->toBeFalse();
});

// --- project_id: POST /issues ---------------------------------------------

test('POST /issues creates the issue in the project given by id or identifier', function () {
    $setup = safeAttributesSetup(['add_issues']);
    Passport::actingAs($setup['user']);

    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => $setup['project']->id]))
        ->assertCreated()->assertJsonPath('data.project_id', $setup['project']->id);
    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => $setup['project']->identifier]))
        ->assertCreated()->assertJsonPath('data.project_id', $setup['project']->id);
});

test('POST /issues needs a project the caller may add issues to, without telling a missing one apart', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $readOnly = safeAttributesSetup([]);
    Member::factory()->for($readOnly['project'])->for($setup['user'])->create()->roles()->attach($readOnly['role']);
    Passport::actingAs($setup['user']);

    $this->postJson('/api/v1/issues', safeAttributesPayload($setup))->assertUnprocessable()->assertJsonValidationErrors(['project_id']);
    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => $readOnly['project']->id]))->assertForbidden();
    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => 999999]))->assertForbidden();
    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => 'no-such-project']))->assertForbidden();

    expect(Issue::query()->count())->toBe(0);
});

test('POST /issues applies the tracker limits of the caller\'s add_issues roles (A1-27)', function () {
    $setup = safeAttributesSetup(['add_issues']);
    $allowed = Tracker::factory()->create(['default_status_id' => $setup['status']->id]);
    $setup['project']->trackers()->attach($allowed);
    $setup['role']->update(['settings' => ['permissions_all_trackers' => ['add_issues' => false], 'permissions_tracker_ids' => ['add_issues' => [$allowed->id]]]]);
    Passport::actingAs($setup['user']);

    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => $setup['project']->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['tracker_id']);
    $this->postJson('/api/v1/issues', safeAttributesPayload($setup, ['project_id' => $setup['project']->id, 'tracker_id' => $allowed->id]))
        ->assertCreated();
});
