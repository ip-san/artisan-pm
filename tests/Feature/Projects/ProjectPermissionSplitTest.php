<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function splitPermissionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the publicity checkbox is offered and saved only with select_project_publicity', function () {
    $project = Project::factory()->create(['is_public' => true]);
    $project->trackers()->attach(Tracker::factory()->create());
    $withPermission = splitPermissionMember($project, ['edit_project', 'select_project_publicity']);
    $without = splitPermissionMember($project, ['edit_project']);

    Livewire::actingAs($without)->test('projects.form', ['project' => $project])
        ->assertDontSee('公開プロジェクト')
        ->set('is_public', false)->call('save')->assertHasNoErrors();
    expect($project->fresh()->is_public)->toBeTrue();

    Livewire::actingAs($withPermission)->test('projects.form', ['project' => $project->fresh()])
        ->assertSee('公開プロジェクト')
        ->set('is_public', false)->call('save')->assertHasNoErrors();
    expect($project->fresh()->is_public)->toBeFalse();
});

test('a creator whose default role lacks select_project_publicity gets the site default publicity', function () {
    $holder = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($holder)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    Setting::set('default_projects_public', false);
    Setting::set('new_project_user_role_id', Role::factory()->create(['permissions' => ['view_issues']])->id);
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($holder)->test('projects.form')
        ->set('name', 'Quiet')->set('identifier', 'quiet')->set('trackerIds', [$tracker->id])->set('is_public', true)
        ->call('save')->assertHasNoErrors();

    expect(Project::where('identifier', 'quiet')->firstOrFail()->is_public)->toBeFalse();
});

test('a creator whose default role has select_project_publicity may choose', function () {
    $holder = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($holder)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    Setting::set('default_projects_public', false);
    Setting::set('new_project_user_role_id', Role::factory()->create(['permissions' => ['view_issues', 'select_project_publicity']])->id);
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($holder)->test('projects.form')
        ->set('name', 'Open')->set('identifier', 'open-one')->set('trackerIds', [$tracker->id])->set('is_public', true)
        ->call('save')->assertHasNoErrors();

    expect(Project::where('identifier', 'open-one')->firstOrFail()->is_public)->toBeTrue();
});

test('administrators may always choose publicity', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['is_public' => true]);
    $project->trackers()->attach(Tracker::factory()->create());

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])->set('is_public', false)->call('save');

    expect($project->fresh()->is_public)->toBeFalse();
});

test('the api ignores is_public without select_project_publicity', function () {
    $project = Project::factory()->create(['is_public' => true]);
    $project->trackers()->attach(Tracker::factory()->create());
    $without = splitPermissionMember($project, ['edit_project']);
    $with = splitPermissionMember($project, ['edit_project', 'select_project_publicity']);

    Passport::actingAs($without);
    $this->putJson("/api/v1/projects/{$project->id}", ['is_public' => false])->assertOk();
    expect($project->fresh()->is_public)->toBeTrue();

    Passport::actingAs($with);
    $this->putJson("/api/v1/projects/{$project->id}", ['is_public' => false])->assertOk();
    expect($project->fresh()->is_public)->toBeFalse();
});

test('edit_project alone no longer opens the activities page', function () {
    $project = Project::factory()->create();
    $editor = splitPermissionMember($project, ['edit_project']);
    $manager = splitPermissionMember($project, ['manage_project_activities']);

    Livewire::actingAs($editor)->test('projects.activities', ['project' => $project])->assertForbidden();
    Livewire::actingAs($manager)->test('projects.activities', ['project' => $project])->assertOk();
});

test('view_members lists memberships; manage_members alone does not', function () {
    $project = Project::factory()->create();
    $manager = splitPermissionMember($project, ['manage_members']);
    $viewer = splitPermissionMember($project, ['view_members']);

    Passport::actingAs($manager);
    $this->getJson("/api/v1/projects/{$project->id}/memberships")->assertForbidden();

    Passport::actingAs($viewer);
    $this->getJson("/api/v1/projects/{$project->id}/memberships")->assertOk();
});

test('the stand-in migration hands the new permissions to holders of the old ones', function () {
    $editor = Role::factory()->create(['permissions' => ['edit_project']]);
    $memberManager = Role::factory()->create(['permissions' => ['manage_members', 'view_issues']]);
    $bystander = Role::factory()->create(['permissions' => ['view_issues']]);

    $migration = require database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn ($file) => str_ends_with($file, 'grant_project_permission_stand_ins.php')));
    $migration->up();
    $migration->up();

    expect($editor->fresh()->permissions)->toContain('select_project_publicity', 'manage_project_activities')->not->toContain('view_members')
        ->and($memberManager->fresh()->permissions)->toContain('view_members')->not->toContain('select_project_publicity')
        ->and($bystander->fresh()->permissions)->toBe(['view_issues'])
        ->and(count(array_keys($editor->fresh()->permissions, 'select_project_publicity', true)))->toBe(1);
});
