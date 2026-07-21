<?php

use App\Enums\ProjectModuleKey;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Livewire\Livewire;

test('an admin can create a project with modules through the form', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'New Project')
        ->set('identifier', 'new-project')
        ->set('modules', [ProjectModuleKey::IssueTracking->value, ProjectModuleKey::Wiki->value])
        ->call('save')
        ->assertRedirect();

    $project = Project::where('identifier', 'new-project')->firstOrFail();

    expect($project->name)->toBe('New Project')
        ->and($project->hasModule(ProjectModuleKey::IssueTracking))->toBeTrue()
        ->and($project->hasModule(ProjectModuleKey::Boards))->toBeFalse();
});

test('a non-admin without the create permission cannot open the project form', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.form')->assertForbidden();
});

test('a project member with edit_project can update the project but not delete it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Old name']);
    $role = Role::factory()->create(['permissions' => ['edit_project']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.form', ['project' => $project])
        ->set('name', 'Updated name')
        ->call('save')
        ->assertRedirect();

    expect($project->refresh()->name)->toBe('Updated name')
        ->and($user->can('delete', $project))->toBeFalse();
});

test('the project policy allows guests to view a public project but not a private one', function () {
    // The Livewire project routes are gated behind the `auth` middleware for
    // now (see routes/web.php) — guest-accessible browsing is Phase 1+ scope.
    // This exercises the Policy/AuthorizationService layer directly, which
    // already supports the anonymous-role resolution those future routes
    // will rely on.
    $policy = app(ProjectPolicy::class);

    $public = Project::factory()->create();
    $private = Project::factory()->private()->create();

    expect($policy->view(null, $public))->toBeTrue()
        ->and($policy->view(null, $private))->toBeFalse();
});

test('a non-member cannot manage members of a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)->test('projects.members', ['project' => $project])->assertForbidden();
});
