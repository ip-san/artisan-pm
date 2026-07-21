<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
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
