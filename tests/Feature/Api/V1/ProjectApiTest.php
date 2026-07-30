<?php

use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

test('a user only sees projects they can view', function () {
    $user = User::factory()->create();
    $visible = Project::factory()->create(['name' => 'Visible']);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden']);

    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($visible)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/projects');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

test('viewing a single project the user cannot see is forbidden', function () {
    $user = User::factory()->create();
    $private = Project::factory()->private()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$private->id}")->assertForbidden();
});

test('a public project is visible to any authenticated user', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.identifier', $project->identifier);
});

test('a non-admin cannot create a top-level project via the api', function () {
    $user = User::factory()->create();
    $tracker = Tracker::factory()->create();

    Passport::actingAs($user);

    $this->postJson('/api/v1/projects', [
        'name' => 'New Project',
        'identifier' => 'new-project',
        'tracker_ids' => [$tracker->id],
    ])->assertForbidden();

    expect(Project::where('identifier', 'new-project')->exists())->toBeFalse();
});

test('an admin can create a top-level project via the api', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Passport::actingAs($admin);

    $this->postJson('/api/v1/projects', [
        'name' => 'New Project',
        'identifier' => 'new-project',
        'tracker_ids' => [$tracker->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.identifier', 'new-project');

    $project = Project::where('identifier', 'new-project')->firstOrFail();
    expect($project->trackers->pluck('id')->all())->toBe([$tracker->id]);
});

test('creating a project without is_public falls back to the default_projects_public setting', function () {
    Setting::set('default_projects_public', false);
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Passport::actingAs($admin);

    $this->postJson('/api/v1/projects', [
        'name' => 'Private By Default',
        'identifier' => 'private-by-default',
        'tracker_ids' => [$tracker->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_public', false);

    $project = Project::where('identifier', 'private-by-default')->firstOrFail();
    expect($project->is_public)->toBeFalse();
});

test('a member with add_subprojects can create a subproject via the api', function () {
    $parent = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'add_subprojects']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);
    $tracker = Tracker::factory()->create();

    Passport::actingAs($user);

    $this->postJson('/api/v1/projects', [
        'name' => 'Child Project',
        'identifier' => 'child-project',
        'parent_id' => $parent->id,
        'tracker_ids' => [$tracker->id],
    ])->assertCreated();

    $child = Project::where('identifier', 'child-project')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id);
});

test('a member with edit_project can update their project via the api', function () {
    $project = Project::factory()->create(['name' => 'Old Name']);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');

    expect($project->fresh()->name)->toBe('New Name');
});

test('a member without edit_project cannot update the project via the api', function () {
    $project = Project::factory()->create(['name' => 'Old Name']);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'New Name'])->assertForbidden();

    expect($project->fresh()->name)->toBe('Old Name');
});

test('updating a project to a parent without createSubproject is forbidden', function () {
    $project = Project::factory()->create();
    $newParent = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->putJson("/api/v1/projects/{$project->id}", ['parent_id' => $newParent->id])->assertForbidden();

    expect($project->fresh()->parent_id)->toBeNull();
});

test('detaching an existing subproject to top-level via the api requires the global create-project permission', function () {
    // Matches Redmine's Project#allowed_parents: nil is only offered as a
    // valid target when the user holds add_project globally — edit_project
    // alone isn't enough, even though it's enough to change every other
    // field on the project.
    $parent = Project::factory()->create();
    $project = Project::factory()->create(['parent_id' => $parent->id]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->putJson("/api/v1/projects/{$project->id}", ['parent_id' => null])->assertForbidden();

    expect($project->fresh()->parent_id)->toBe($parent->id);
});

test('an admin can detach an existing subproject to top-level via the api', function () {
    $parent = Project::factory()->create();
    $project = Project::factory()->create(['parent_id' => $parent->id]);
    $admin = User::factory()->admin()->create();

    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$project->id}", ['parent_id' => null])->assertOk();

    expect($project->fresh()->parent_id)->toBeNull();
});

test('a project cannot be reparented under itself or a descendant via the api', function () {
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);

    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$parent->id}", ['parent_id' => $child->id])
        ->assertUnprocessable();

    expect($parent->fresh()->parent_id)->toBeNull();
});

test('removing a tracker still in use by issues is rejected via the api', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $otherTracker = Tracker::factory()->create();
    $project->trackers()->attach([$tracker->id, $otherTracker->id]);
    Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $admin = User::factory()->admin()->create();
    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$project->id}", ['tracker_ids' => [$otherTracker->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tracker_ids');

    expect($project->fresh()->trackers->pluck('id')->sort()->values()->all())->toBe([$tracker->id, $otherTracker->id]);
});

test('a member with close_project can close and reopen a project via the api', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'close_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/close")->assertNoContent();
    expect($project->fresh()->status)->toBe(ProjectStatus::Closed);

    $this->postJson("/api/v1/projects/{$project->id}/reopen")->assertNoContent();
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('only an admin can archive or unarchive a project via the api', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);
    $this->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();

    $admin = User::factory()->admin()->create();
    Passport::actingAs($admin);

    $this->postJson("/api/v1/projects/{$project->id}/archive")->assertNoContent();
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);

    $this->postJson("/api/v1/projects/{$project->id}/unarchive")->assertNoContent();
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('an archived project cannot be reopened or edited via the api even by a member with the right permission', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project', 'close_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    // AuthorizationService::can() rejects every project-scoped permission
    // once a project is archived (Redmine's Project#allows_to? parity),
    // so these routes are already protected without an extra guard here.
    $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'New Name'])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/reopen")->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
    expect($project->fresh()->name)->not->toBe('New Name');
});

test('an admin can delete a project via the api, no confirmation param required', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    Passport::actingAs($admin);

    // Unlike the web UI, the API requires no confirm=identifier param —
    // matches Redmine's ProjectsController#destroy, which unconditionally
    // skips that check for API requests (the caller's credentials are
    // themselves the confirmation).
    $this->deleteJson("/api/v1/projects/{$project->id}")->assertStatus(204);

    expect(Project::find($project->id))->toBeNull();
});

test('deleting a project via the api cascades to its subprojects', function () {
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);

    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/projects/{$parent->id}")->assertStatus(204);

    expect(Project::find($parent->id))->toBeNull()
        ->and(Project::find($child->id))->toBeNull();
});

test('a member with delete_project cannot delete a project that has subprojects via the api', function () {
    $parent = Project::factory()->create();
    Project::factory()->create(['parent_id' => $parent->id]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['delete_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/projects/{$parent->id}")->assertForbidden();

    expect(Project::find($parent->id))->not->toBeNull();
});

test('a member without delete_project cannot delete a project via the api', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();

    expect(Project::find($project->id))->not->toBeNull();
});
