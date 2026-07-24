<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create a subproject with a parent selected in the form', function () {
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'Child Project')
        ->set('identifier', 'child-project')
        ->set('parent_id', $parent->id)
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect();

    $child = Project::where('identifier', 'child-project')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id);
});

test('an admin can reparent an existing project', function () {
    $newParent = Project::factory()->create();
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('parent_id', $newParent->id)
        ->call('save')
        ->assertRedirect();

    expect($project->refresh()->parent_id)->toBe($newParent->id);
});

test('a project cannot be set as its own descendant', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $child->trackers()->attach(Tracker::factory()->create());

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $parent])
        ->set('parent_id', $child->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('a project member with add_subprojects can reach the create form pre-filled with the parent', function () {
    $parent = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['add_subprojects', 'view_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);

    $component = Livewire::actingAs($user)->withQueryParams(['parent_id' => $parent->id])->test('projects.form');

    expect($component->get('parent_id'))->toBe($parent->id);
});

test('a member without add_subprojects cannot reach the create form via a parent link', function () {
    $parent = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)->withQueryParams(['parent_id' => $parent->id])->test('projects.form')->assertForbidden();
});

test('re-targeting parent_id to an unauthorized project after mount is rejected on save', function () {
    $authorizedParent = Project::factory()->create();
    $unauthorizedParent = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['add_subprojects', 'view_project']]);
    Member::factory()->for($authorizedParent)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->withQueryParams(['parent_id' => $authorizedParent->id])
        ->test('projects.form')
        ->set('name', 'Sneaky Child')
        ->set('identifier', 'sneaky-child')
        ->set('trackerIds', [$tracker->id])
        // Simulates a tampered request: the form mounted authorized against
        // $authorizedParent, but the client sends a different parent_id.
        ->set('parent_id', $unauthorizedParent->id)
        ->call('save')
        ->assertForbidden();

    expect(Project::where('identifier', 'sneaky-child')->exists())->toBeFalse();
});

test('clearing parent_id after mount does not escalate to top-level project creation', function () {
    $authorizedParent = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['add_subprojects', 'view_project']]);
    Member::factory()->for($authorizedParent)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->withQueryParams(['parent_id' => $authorizedParent->id])
        ->test('projects.form')
        ->set('name', 'Sneaky Top Level')
        ->set('identifier', 'sneaky-top-level')
        ->set('trackerIds', [$tracker->id])
        ->set('parent_id', null)
        ->call('save')
        ->assertForbidden();

    expect(Project::where('identifier', 'sneaky-top-level')->exists())->toBeFalse();
});

test('reparenting to a project without createSubproject there is rejected even with edit_project on the project itself', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $unauthorizedParent = Project::factory()->create();

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['edit_project', 'view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.form', ['project' => $project])
        ->set('parent_id', $unauthorizedParent->id)
        ->call('save')
        ->assertForbidden();

    expect($project->refresh()->parent_id)->toBeNull();
});

test('editing other fields on an existing subproject does not require createSubproject on its current parent', function () {
    $parent = Project::factory()->create();
    $project = Project::factory()->create(['parent_id' => $parent->id, 'name' => 'Old name']);
    $project->trackers()->attach(Tracker::factory()->create());

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['edit_project', 'view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.form', ['project' => $project])
        ->set('name', 'Updated name')
        ->call('save')
        ->assertRedirect();

    expect($project->refresh())
        ->name->toBe('Updated name')
        ->parent_id->toBe($parent->id);
});

test('a non-admin subproject creator is auto-added as a member with the configured default role', function () {
    $parent = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = User::factory()->create();
    $creatorRole = Role::factory()->create(['permissions' => ['add_subprojects', 'view_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($creatorRole);
    $defaultRole = Role::factory()->create(['name' => 'Contributor']);
    Setting::set('new_project_user_role_id', $defaultRole->id);

    Livewire::actingAs($user)
        ->withQueryParams(['parent_id' => $parent->id])
        ->test('projects.form')
        ->set('name', 'New Child')
        ->set('identifier', 'new-child')
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect();

    $child = Project::where('identifier', 'new-child')->firstOrFail();
    $member = Member::where('project_id', $child->id)->where('user_id', $user->id)->first();

    expect($member)->not->toBeNull()
        ->and($member->roles->pluck('id')->all())->toBe([$defaultRole->id]);
});

test('a non-admin subproject creator falls back to the first givable role when no default is configured', function () {
    $parent = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = User::factory()->create();
    $creatorRole = Role::factory()->create(['permissions' => ['add_subprojects', 'view_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($creatorRole);

    Livewire::actingAs($user)
        ->withQueryParams(['parent_id' => $parent->id])
        ->test('projects.form')
        ->set('name', 'Fallback Child')
        ->set('identifier', 'fallback-child')
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect();

    $child = Project::where('identifier', 'fallback-child')->firstOrFail();
    $member = Member::where('project_id', $child->id)->where('user_id', $user->id)->first();

    expect($member)->not->toBeNull()
        ->and($member->roles)->not->toBeEmpty();
});

test('an admin subproject creator is not auto-added as a member', function () {
    $parent = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'Admin Created')
        ->set('identifier', 'admin-created')
        ->set('parent_id', $parent->id)
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect();

    $created = Project::where('identifier', 'admin-created')->firstOrFail();

    expect(Member::where('project_id', $created->id)->where('user_id', $admin->id)->exists())->toBeFalse();
});
