<?php

use App\Enums\ProjectStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('a member with close_project can close and reopen a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['close_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('closeProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Closed);

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('reopenProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a member without close_project cannot close a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->call('closeProject')
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('only an admin can archive or unarchive a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['close_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->call('archiveProject')
        ->assertForbidden();

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('projects.show', ['project' => $project])->call('archiveProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);

    Livewire::actingAs($admin)->test('projects.show', ['project' => $project])->call('unarchiveProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a non-active project shows a status badge on its show page', function () {
    $project = Project::factory()->create();
    $project->status = ProjectStatus::Closed;
    $project->save();

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->assertSee('クローズ');
});
