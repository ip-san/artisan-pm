<?php

use App\Enums\IssueVisibility;
use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

function authService(): AuthorizationService
{
    return app(AuthorizationService::class);
}

test('admins can do anything regardless of membership', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->private()->create();

    expect(authService()->can($admin, 'edit_project', $project))->toBeTrue();
});

test('anonymous visitors are granted public permissions on public projects only', function () {
    Role::factory()->create([
        'builtin' => RoleBuiltin::Anonymous->value,
        'permissions' => ['view_project'],
    ]);

    $publicProject = Project::factory()->create();
    $privateProject = Project::factory()->private()->create();

    expect(authService()->can(null, 'view_project', $publicProject))->toBeTrue()
        ->and(authService()->can(null, 'view_project', $privateProject))->toBeFalse();
});

test('a logged-in non-member gets the non-member role permissions on public projects', function () {
    Role::factory()->create([
        'builtin' => RoleBuiltin::NonMember->value,
        'permissions' => ['view_project'],
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->create();

    expect(authService()->can($user, 'view_project', $project))->toBeTrue()
        ->and(authService()->can($user, 'edit_project', $project))->toBeFalse();
});

test('a project member is granted permissions from their assigned role', function () {
    $user = User::factory()->create();
    $project = Project::factory()->private()->create();
    $role = Role::factory()->create(['permissions' => ['edit_project']]);

    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    expect(authService()->can($user, 'edit_project', $project))->toBeTrue()
        ->and(authService()->can($user, 'delete_project', $project))->toBeFalse();
});

test('a member gains permissions through group membership as well as direct membership', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($user);

    $project = Project::factory()->private()->create();
    $role = Role::factory()->create(['permissions' => ['manage_members']]);

    $groupMember = Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id]);
    $groupMember->roles()->attach($role);

    expect(authService()->can($user, 'manage_members', $project))->toBeTrue();
});

test('private projects grant no permissions to non-members', function () {
    Role::factory()->create([
        'builtin' => RoleBuiltin::NonMember->value,
        'permissions' => ['view_project'],
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->private()->create();

    expect(authService()->can($user, 'view_project', $project))->toBeFalse();
});

test('module-gated permissions are denied when the owning module is disabled', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->syncModules([]);

    $role = Role::factory()->create(['permissions' => ['manage_versions']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    expect(authService()->can($user, 'manage_versions', $project))->toBeFalse();

    $project->syncModules([ProjectModuleKey::IssueTracking]);
    $project->refresh();

    expect(authService()->can($user, 'manage_versions', $project))->toBeTrue();
});

test('unknown permission keys are always denied', function () {
    $admin = User::factory()->create();
    $project = Project::factory()->create();

    expect(authService()->can($admin, 'not_a_real_permission', $project))->toBeFalse();
});

test('a role with own-only issue visibility restricts issueVisibilityFor to Own', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['issues_visibility' => IssueVisibility::Own->value]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    expect(authService()->issueVisibilityFor($user, $project))->toBe(IssueVisibility::Own);
});

test('holding any role with All visibility wins over an Own-only role', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $ownRole = Role::factory()->create(['issues_visibility' => IssueVisibility::Own->value]);
    $allRole = Role::factory()->create(['issues_visibility' => IssueVisibility::All->value]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach([$ownRole->id, $allRole->id]);

    expect(authService()->issueVisibilityFor($user, $project))->toBe(IssueVisibility::All);
});

test('a non-member on a public project gets All issue visibility', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    expect(authService()->issueVisibilityFor($user, $project))->toBe(IssueVisibility::All);
});

test('hasSiteWideUserVisibility is always true for admins', function () {
    $admin = User::factory()->admin()->create();

    expect(authService()->hasSiteWideUserVisibility($admin))->toBeTrue();
});

test('hasSiteWideUserVisibility for a guest (null user) consults the Anonymous builtin role', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value, 'users_visibility' => 'all']);

    expect(authService()->hasSiteWideUserVisibility(null))->toBeTrue();
});

test('hasSiteWideUserVisibility for a user with no membership anywhere consults the NonMember builtin role', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'users_visibility' => 'members_of_visible_projects']);
    $user = User::factory()->create();

    expect(authService()->hasSiteWideUserVisibility($user))->toBeFalse();
});

test('hasSiteWideUserVisibility is true when any of the user\'s roles anywhere grants all', function () {
    $project = Project::factory()->create();
    $restrictedProject = Project::factory()->create();
    $restrictedRole = Role::factory()->create(['users_visibility' => 'members_of_visible_projects']);
    $allRole = Role::factory()->create(['users_visibility' => 'all']);
    $user = User::factory()->create();
    Member::factory()->for($restrictedProject)->for($user)->create()->roles()->attach($restrictedRole);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($allRole);

    expect(authService()->hasSiteWideUserVisibility($user))->toBeTrue();
});

test('hasSiteWideUserVisibility is false when every one of the user\'s roles is restricted', function () {
    $project = Project::factory()->create();
    $restrictedRole = Role::factory()->create(['users_visibility' => 'members_of_visible_projects']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($restrictedRole);

    expect(authService()->hasSiteWideUserVisibility($user))->toBeFalse();
});

test('hasSiteWideUserVisibility resolves through group membership, not just direct membership', function () {
    $project = Project::factory()->create();
    $allRole = Role::factory()->create(['users_visibility' => 'all']);
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($user);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($allRole);

    expect(authService()->hasSiteWideUserVisibility($user))->toBeTrue();
});

test('visibleProjectIds for an admin includes every project', function () {
    $admin = User::factory()->admin()->create();
    $public = Project::factory()->create(['is_public' => true]);
    $private = Project::factory()->private()->create();

    $ids = authService()->visibleProjectIds($admin);

    expect($ids)->toContain($public->id, $private->id);
});

test('visibleProjectIds for a non-member includes public projects but excludes private ones', function () {
    $user = User::factory()->create();
    $public = Project::factory()->create(['is_public' => true]);
    $private = Project::factory()->private()->create();

    $ids = authService()->visibleProjectIds($user);

    expect($ids)->toContain($public->id)
        ->not->toContain($private->id);
});

test('visibleProjectIds for a member of a private project includes it', function () {
    $user = User::factory()->create();
    $private = Project::factory()->private()->create();
    Member::factory()->for($private)->for($user)->create();

    expect(authService()->visibleProjectIds($user))->toContain($private->id);
});

test('visibleProjectIds excludes archived projects even when public', function () {
    $user = User::factory()->create();
    $archived = Project::factory()->create(['is_public' => true, 'status' => 'archived']);

    expect(authService()->visibleProjectIds($user))->not->toContain($archived->id);
});
