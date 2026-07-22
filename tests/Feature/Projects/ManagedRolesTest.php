<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Livewire\Livewire;

function managedRolesManager(Project $project, Role $role): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('a manager with all_roles_managed sees every givable role', function () {
    $project = Project::factory()->create();
    $manager = Role::factory()->withPermissions(['manage_members'])->create();
    $other = Role::factory()->create();
    $user = managedRolesManager($project, $manager);

    $roles = app(AuthorizationService::class)->managedRolesFor($user, $project);

    expect($roles->pluck('id'))->toContain($manager->id, $other->id);
});

test('a manager restricted to a subset only sees its managed roles', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create(['name' => 'Allowed']);
    $forbidden = Role::factory()->create(['name' => 'Forbidden']);
    $manager = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $manager->managedRoles()->attach($allowed);
    $user = managedRolesManager($project, $manager);

    $roles = app(AuthorizationService::class)->managedRolesFor($user, $project);

    expect($roles->pluck('id'))->toContain($allowed->id)->not->toContain($forbidden->id);
});

test('a user without manage_members on any role manages nothing', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create();
    $user = managedRolesManager($project, $role);

    expect(app(AuthorizationService::class)->managedRolesFor($user, $project))->toBeEmpty();
});

test('an admin manages every givable role regardless of membership', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();
    $other = Role::factory()->create();

    $roles = app(AuthorizationService::class)->managedRolesFor($admin, $project);

    expect($roles->pluck('id'))->toContain($other->id);
});

test('the members screen only offers checkboxes for roles the current user manages', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create(['name' => 'Allowed']);
    $forbidden = Role::factory()->create(['name' => 'Forbidden']);
    $manager = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $manager->managedRoles()->attach($allowed);
    $user = managedRolesManager($project, $manager);

    Livewire::actingAs($user)
        ->test('projects.members', ['project' => $project])
        ->assertSee('Allowed')
        ->assertDontSee('Forbidden');
});

test('submitting a role outside the managed set is rejected', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create();
    $forbidden = Role::factory()->create();
    $manager = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $manager->managedRoles()->attach($allowed);
    $user = managedRolesManager($project, $manager);
    $target = User::factory()->create();

    Livewire::actingAs($user)
        ->test('projects.members', ['project' => $project])
        ->call('selectUser', $target->id)
        ->set('roleIds', [$allowed->id, $forbidden->id])
        ->call('addMember')
        ->assertHasErrors(['roleIds.1']);
});

test('editing a member preserves a role outside the editor\'s managed set', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create();
    $unmanaged = Role::factory()->create();
    $manager = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $manager->managedRoles()->attach($allowed);
    $user = managedRolesManager($project, $manager);

    $target = User::factory()->create();
    $targetMember = Member::factory()->for($project)->for($target)->create();
    $targetMember->roles()->attach([$allowed->id, $unmanaged->id]);

    Livewire::actingAs($user)
        ->test('projects.members', ['project' => $project])
        ->call('editMember', $targetMember->id)
        ->set('roleIds', [])
        ->call('addMember');

    expect($targetMember->fresh()->roles->pluck('id'))
        ->not->toContain($allowed->id)
        ->toContain($unmanaged->id);
});
