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

function closedProjectMember(array $permissions): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    $project->status = ProjectStatus::Closed;
    $project->save();

    return [$project->fresh(), $user];
}

test('a closed project keeps the read-only administration permissions Redmine flags as read', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project))->toBeTrue();
})->with(['edit_project', 'close_project', 'delete_project', 'select_project_modules']);

test('a closed project blocks manage_members, add_subprojects and manage_public_queries', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project))->toBeFalse();

    $project->status = ProjectStatus::Active;
    $project->save();

    expect(app(App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project->fresh()))->toBeTrue();
})->with(['manage_members', 'add_subprojects', 'manage_public_queries']);

test('a closed project still lets its members read and reopen it but not manage members', function () {
    [$project, $user] = closedProjectMember(['view_project', 'close_project', 'manage_members']);

    Livewire::actingAs($user)->test('projects.members', ['project' => $project])->assertForbidden();

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('reopenProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);

    Livewire::actingAs($user)->test('projects.members', ['project' => $project->fresh()])->assertOk();
});

test('a closed project lets a member still edit and delete their own notes and messages', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project))->toBeTrue();
})->with(['edit_own_issue_notes', 'delete_own_messages']);

test('a closed project still blocks the write permissions of its modules', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project))->toBeFalse();
})->with(['add_issues', 'edit_issues', 'log_time', 'edit_wiki_pages', 'add_messages', 'manage_versions']);
