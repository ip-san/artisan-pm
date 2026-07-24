<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

function apiMembershipManager(Project $project, array $permissions = ['manage_members'], bool $allRolesManaged = true): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'all_roles_managed' => $allRolesManaged]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/memberships")->assertUnauthorized();
});

test('a member with manage_members can list a project\'s memberships', function () {
    $project = Project::factory()->create();
    $user = apiMembershipManager($project);
    $member = Member::factory()->for($project)->create();

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/memberships");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($member->id);
});

test('a member without manage_members cannot list memberships', function () {
    $project = Project::factory()->create();
    $user = apiMembershipManager($project, ['view_issues']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/memberships")->assertForbidden();
});

test('a manager can add a user as a member with roles', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $target = User::factory()->create();
    $role = Role::factory()->create();

    Passport::actingAs($manager);

    $response = $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'user_id' => $target->id,
        'role_ids' => [$role->id],
    ]);

    $response->assertCreated()->assertJsonPath('data.user_id', $target->id);

    $member = Member::where('project_id', $project->id)->where('user_id', $target->id)->firstOrFail();
    expect($member->roles->pluck('id')->all())->toBe([$role->id]);
});

test('a manager can add a group as a member', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $group = Group::factory()->create();
    $role = Role::factory()->create();

    Passport::actingAs($manager);

    $response = $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'group_id' => $group->id,
        'role_ids' => [$role->id],
    ]);

    $response->assertCreated()->assertJsonPath('data.group_id', $group->id);
});

test('providing both user_id and group_id is rejected', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $target = User::factory()->create();
    $group = Group::factory()->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'user_id' => $target->id,
        'group_id' => $group->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
});

test('providing neither user_id nor group_id is rejected', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/memberships", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id', 'group_id']);
});

test('a user cannot be made a member of the same project twice', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $target = User::factory()->create();
    Member::factory()->for($project)->for($target)->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'user_id' => $target->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
});

test('a manager restricted to a subset of roles cannot grant a role outside that set', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create(['name' => 'Allowed']);
    $forbidden = Role::factory()->create(['name' => 'Forbidden']);
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $managerRole->managedRoles()->attach($allowed);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);
    $target = User::factory()->create();

    Passport::actingAs($manager);

    $response = $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'user_id' => $target->id,
        'role_ids' => [$allowed->id, $forbidden->id],
    ]);

    $response->assertCreated();
    $member = Member::where('project_id', $project->id)->where('user_id', $target->id)->firstOrFail();
    expect($member->roles->pluck('id')->all())->toBe([$allowed->id]);
});

test('creating a membership with only unmanaged roles fails because no role would remain', function () {
    $project = Project::factory()->create();
    $forbidden = Role::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);
    $target = User::factory()->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/memberships", [
        'user_id' => $target->id,
        'role_ids' => [$forbidden->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
});

test('a manager can update a member\'s roles', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $target = User::factory()->create();
    $member = Member::factory()->for($project)->for($target)->create();
    $oldRole = Role::factory()->create();
    $newRole = Role::factory()->create();
    $member->roles()->attach($oldRole);

    Passport::actingAs($manager);

    $this->putJson("/api/v1/memberships/{$member->id}", ['role_ids' => [$newRole->id]])
        ->assertOk()
        ->assertJsonPath('data.role_ids', [$newRole->id]);

    expect($member->fresh()->roles->pluck('id')->all())->toBe([$newRole->id]);
});

test('updating a member preserves a role outside the editor\'s managed set', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create();
    $unmanaged = Role::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $managerRole->managedRoles()->attach($allowed);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);
    $target = User::factory()->create();
    $member = Member::factory()->for($project)->for($target)->create();
    $member->roles()->attach([$allowed->id, $unmanaged->id]);

    Passport::actingAs($manager);

    $this->putJson("/api/v1/memberships/{$member->id}", ['role_ids' => []])->assertOk();

    expect($member->fresh()->roles->pluck('id'))
        ->not->toContain($allowed->id)
        ->toContain($unmanaged->id);
});

test('a manager can remove a member whose roles are all within their managed set', function () {
    $project = Project::factory()->create();
    $manager = apiMembershipManager($project);
    $member = Member::factory()->for($project)->create();
    $member->roles()->attach(Role::factory()->create());

    Passport::actingAs($manager);

    $this->deleteJson("/api/v1/memberships/{$member->id}")->assertNoContent();

    expect(Member::find($member->id))->toBeNull();
});

test('a manager cannot remove a member holding a role outside their managed set', function () {
    $project = Project::factory()->create();
    $allowed = Role::factory()->create();
    $unmanaged = Role::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['all_roles_managed' => false]);
    $managerRole->managedRoles()->attach($allowed);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);
    $target = User::factory()->create();
    $member = Member::factory()->for($project)->for($target)->create();
    $member->roles()->attach($unmanaged);

    Passport::actingAs($manager);

    $this->deleteJson("/api/v1/memberships/{$member->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_ids']);

    expect(Member::find($member->id))->not->toBeNull();
});
