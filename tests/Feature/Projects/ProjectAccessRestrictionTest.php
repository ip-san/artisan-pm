<?php

use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Livewire\Livewire;

function restrictionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('an archived project is not visible to a regular member', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
    $user = restrictionMember($project, ['view_project', 'view_issues']);

    expect($user->can('view', $project))->toBeFalse();
});

test('an archived project remains visible to an admin', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
    $admin = User::factory()->admin()->create();

    expect($admin->can('view', $project))->toBeTrue();
});

test('an archived public project is not visible to a guest either', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Archived, 'is_public' => true]);

    expect(app(ProjectPolicy::class)->view(null, $project))->toBeFalse();
});

test('archived projects are excluded from the project list', function () {
    $visible = Project::factory()->create();
    $archived = Project::factory()->create(['status' => ProjectStatus::Archived]);
    $user = restrictionMember($visible, ['view_project']);
    restrictionMember($archived, ['view_project']);

    $names = Livewire::actingAs($user)
        ->test('projects.index')
        ->get('projects')
        ->pluck('name');

    expect($names)->toContain($visible->name)
        ->not->toContain($archived->name);
});

test('a member cannot create issues in a closed project even with add_issues', function () {
    $project = Project::factory()->closed()->create();
    $user = restrictionMember($project, ['view_issues', 'add_issues']);

    expect($user->can('create', [Issue::class, $project]))->toBeFalse();
});

test('a member can still view issues in a closed project', function () {
    $project = Project::factory()->closed()->create();
    $user = restrictionMember($project, ['view_issues']);

    expect($user->can('viewAny', [Issue::class, $project]))->toBeTrue();
});

test('project management permissions still work on a closed project', function () {
    $project = Project::factory()->closed()->create();
    $user = restrictionMember($project, ['close_project', 'edit_project']);

    expect($user->can('close', $project))->toBeTrue()
        ->and($user->can('update', $project))->toBeTrue();
});

test('no action is allowed on an archived project even for a project manager', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
    $user = restrictionMember($project, ['view_issues', 'add_issues', 'edit_project']);

    expect($user->can('viewAny', [Issue::class, $project]))->toBeFalse()
        ->and($user->can('update', $project))->toBeFalse();
});
