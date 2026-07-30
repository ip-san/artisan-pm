<?php

use App\Enums\RoleBuiltin;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Livewire\Livewire;

test('an admin can add a member with roles by selecting a user from the search dropdown', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', $user->name)
        ->call('selectUser', $user->id)
        ->set('roleIds', [$role->id])
        ->call('addMember');

    $member = Member::where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();
    expect($member->roles->pluck('id')->all())->toBe([$role->id]);
});

test('typing a name or email narrows the user search dropdown to matching, not-yet-member users', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $matching = User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
    $nonMatching = User::factory()->create(['name' => 'Bob Other', 'email' => 'bob@other.com']);
    $existingMember = User::factory()->create(['name' => 'Alice Already Member']);
    Member::factory()->for($project)->for($existingMember)->create();

    $candidates = Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', 'Alice')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($matching->id)
        ->not->toContain($nonMatching->id)
        ->not->toContain($existingMember->id);
});

test('the user search dropdown excludes locked, pending, and deleted users', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $active = User::factory()->create(['name' => 'Findable Active']);
    $locked = User::factory()->create(['name' => 'Findable Locked', 'status' => 'locked']);
    $registered = User::factory()->create(['name' => 'Findable Registered', 'status' => 'registered']);
    $deleted = User::factory()->create(['name' => 'Findable Deleted', 'status' => 'deleted']);

    $candidates = Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', 'Findable')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($active->id)
        ->not->toContain($locked->id)
        ->not->toContain($registered->id)
        ->not->toContain($deleted->id);
});

test('a role with the default users_visibility (all) can find any active user regardless of project membership', function () {
    $project = Project::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['users_visibility' => 'all']);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);

    $strangerElsewhere = User::factory()->create(['name' => 'Unrelated Stranger']);

    $candidates = Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', 'Unrelated')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($strangerElsewhere->id);
});

test('a role restricted to members_of_visible_projects cannot find a user with no shared or public project', function () {
    $project = Project::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['users_visibility' => 'members_of_visible_projects']);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);

    $otherProject = Project::factory()->create(['is_public' => false]);
    $strangerElsewhere = User::factory()->create(['name' => 'Unrelated Stranger']);
    Member::factory()->for($otherProject)->for($strangerElsewhere)->create();

    $candidates = Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', 'Unrelated')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->not->toContain($strangerElsewhere->id);
});

test('a role restricted to members_of_visible_projects can still find a user who is a member of a public project', function () {
    $project = Project::factory()->create();
    $managerRole = Role::factory()->withPermissions(['manage_members'])->create(['users_visibility' => 'members_of_visible_projects']);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($managerRole);

    $publicProject = Project::factory()->create(['is_public' => true]);
    $memberOfPublicProject = User::factory()->create(['name' => 'Public Project Member']);
    Member::factory()->for($publicProject)->for($memberOfPublicProject)->create();

    $candidates = Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $project])
        ->set('userSearch', 'Public Project')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($memberOfPublicProject->id);
});

test('a role restricted to members_of_visible_projects can always find the searching user themselves', function () {
    // The manager reaches a *different* public project's members screen
    // via the builtin NonMember role (no explicit Member row of their own
    // there) — this isolates the self-inclusion branch of the query from
    // the "already an existing member is excluded" filter, which would
    // otherwise hide them from their own home project's candidate list
    // regardless of whether the self-branch works.
    $homeProject = Project::factory()->create();
    $managerRole = Role::factory()->create(['users_visibility' => 'members_of_visible_projects']);
    $manager = User::factory()->create(['name' => 'Searching Self']);
    Member::factory()->for($homeProject)->for($manager)->create()->roles()->attach($managerRole);

    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['manage_members']]);
    $publicProject = Project::factory()->create(['is_public' => true]);

    $candidates = Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $publicProject])
        ->set('userSearch', 'Searching Self')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($manager->id);
});

test('editing an existing member prefills the form and updates roles on submit', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $roleA = Role::factory()->create();
    $roleB = Role::factory()->create();

    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($roleA);

    $component = Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->call('editMember', $member->id);

    expect($component->get('selectedUserId'))->toBe($user->id)
        ->and($component->get('roleIds'))->toBe([$roleA->id]);

    $component->set('roleIds', [$roleB->id])->call('addMember');

    expect(Member::where('project_id', $project->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and($member->fresh()->roles->pluck('id')->all())->toBe([$roleB->id]);
});

test('cancelling an edit resets the form', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $member = Member::factory()->for($project)->create();
    $member->roles()->attach(Role::factory()->create());

    Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->call('editMember', $member->id)
        ->call('cancelEdit')
        ->assertSet('userSearch', '')
        ->assertSet('selectedUserId', null)
        ->assertSet('roleIds', []);
});

test('a group member cannot be opened for edit through editMember', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $group = Group::factory()->create();
    $member = Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null]);

    Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->call('editMember', $member->id)
        ->assertStatus(404);
});

test('an admin can add a group as a project member with roles', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $group = Group::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->set('addType', 'group')
        ->set('groupId', $group->id)
        ->set('roleIds', [$role->id])
        ->call('addMember');

    $member = Member::where('project_id', $project->id)->where('group_id', $group->id)->firstOrFail();
    expect($member->roles->pluck('id')->all())->toBe([$role->id]);
});

test('a user in a project-member group inherits the group role', function () {
    $project = Project::factory()->create();
    $group = Group::factory()->create();
    $user = User::factory()->create();
    $group->users()->attach($user);
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null]);
    $member->roles()->attach($role);

    expect(app(AuthorizationService::class)->can($user, 'view_issues', $project))->toBeTrue();
});

test('selectUser cannot echo back the name or email of a user outside the visible set', function () {
    $project = Project::factory()->create();
    $restrictedRole = Role::factory()->withPermissions(['manage_members'])->create(['users_visibility' => 'members_of_visible_projects']);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($restrictedRole);

    $unrelatedProject = Project::factory()->private()->create();
    $hiddenUser = User::factory()->create(['name' => 'Hidden Name', 'email' => 'hidden@example.com']);
    Member::factory()->for($unrelatedProject)->for($hiddenUser)->create();

    Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $project])
        ->call('selectUser', $hiddenUser->id)
        ->assertStatus(404);
});

test('addMember rejects a directly-set selectedUserId outside the visible set even without going through selectUser', function () {
    $project = Project::factory()->create();
    $restrictedRole = Role::factory()->withPermissions(['manage_members'])->create(['users_visibility' => 'members_of_visible_projects']);
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach($restrictedRole);

    $unrelatedProject = Project::factory()->private()->create();
    $hiddenUser = User::factory()->create();
    Member::factory()->for($unrelatedProject)->for($hiddenUser)->create();

    Livewire::actingAs($manager)
        ->test('projects.members', ['project' => $project])
        ->set('selectedUserId', $hiddenUser->id)
        ->set('roleIds', [$restrictedRole->id])
        ->call('addMember')
        ->assertStatus(404);

    expect(Member::where('project_id', $project->id)->where('user_id', $hiddenUser->id)->exists())->toBeFalse();
});
