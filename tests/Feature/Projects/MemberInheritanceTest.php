<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\MemberInheritance;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, Role>  $roles
 */
function addInheritanceMember(Project $project, User|Group $principal, array $roles): Member
{
    $member = Member::factory()->for($project)->create([
        'user_id' => $principal instanceof User ? $principal->id : null,
        'group_id' => $principal instanceof Group ? $principal->id : null,
    ]);
    $member->roles()->attach(collect($roles)->pluck('id'));

    return $member;
}

/**
 * The (role id => inherited_from) rows the principal holds in the project.
 *
 * @return array<int, int|null>
 */
function inheritanceRoleRows(Project $project, User|Group $principal): array
{
    return DB::table('member_roles')
        ->join('members', 'members.id', '=', 'member_roles.member_id')
        ->where('members.project_id', $project->id)
        ->where($principal instanceof User ? 'members.user_id' : 'members.group_id', $principal->id)
        ->orderBy('member_roles.role_id')
        ->pluck('member_roles.inherited_from', 'member_roles.role_id')
        ->map(fn ($id) => $id === null ? null : (int) $id)
        ->all();
}

/**
 * A parent with an inheriting child and grandchild, and an administrator.
 *
 * @return array{0: Project, 1: Project, 2: Project, 3: User}
 */
function inheritingTree(): array
{
    $parent = Project::factory()->create(['is_public' => false]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false, 'inherit_members' => true]);
    $grandchild = Project::factory()->create(['parent_id' => $child->id, 'is_public' => false, 'inherit_members' => true]);

    return [$parent, $child, $grandchild, User::factory()->admin()->create()];
}

function memberIn(Project $project, User $user): ?Member
{
    return Member::query()->where('project_id', $project->id)->where('user_id', $user->id)->first();
}

function sourceRowId(Member $member, Role $role): int
{
    return (int) DB::table('member_roles')->where('member_id', $member->id)->where('role_id', $role->id)->value('id');
}

test('a new project does not inherit members by default', function () {
    $parent = Project::factory()->create();
    $user = User::factory()->create();
    addInheritanceMember($parent, $user, [Role::factory()->create()]);

    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $child->update(['name' => 'Renamed']);

    expect($child->refresh()->inherit_members)->toBeFalse()
        ->and($child->members()->count())->toBe(0);
});

test('turning inherit_members on copies the parent members and their roles', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $developer = Role::factory()->create();
    $reporter = Role::factory()->create();
    $userMember = addInheritanceMember($parent, $user, [$developer, $reporter]);
    $groupMember = addInheritanceMember($parent, $group, [$reporter]);

    $child->update(['inherit_members' => true]);

    expect(inheritanceRoleRows($child, $user))->toBe([
        $developer->id => sourceRowId($userMember, $developer),
        $reporter->id => sourceRowId($userMember, $reporter),
    ])->and(inheritanceRoleRows($child, $group))->toBe([
        $reporter->id => sourceRowId($groupMember, $reporter),
    ]);
});

test('an inherited membership grants the parent role in the subproject, including to group users', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues']]);
    $user = User::factory()->create();
    $groupUser = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($groupUser);
    addInheritanceMember($parent, $user, [$role]);
    addInheritanceMember($parent, $group, [$role]);

    $authorization = app(AuthorizationService::class);

    expect($authorization->can($user, 'view_issues', $child))->toBeFalse()
        ->and($authorization->can($groupUser, 'view_issues', $child))->toBeFalse();

    $child->update(['inherit_members' => true]);

    expect($authorization->can($user, 'view_issues', $child))->toBeTrue()
        ->and($authorization->can($groupUser, 'view_issues', $child))->toBeTrue();
});

test('turning inherit_members off removes the inherited roles but keeps the ones given in the subproject', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    $parentRole = Role::factory()->create(['permissions' => ['view_project', 'manage_members']]);
    $childRole = Role::factory()->create(['permissions' => ['view_project']]);
    $inheritedOnly = User::factory()->create();
    $mixed = User::factory()->create();
    addInheritanceMember($parent, $inheritedOnly, [$parentRole]);
    addInheritanceMember($parent, $mixed, [$parentRole]);
    $child->update(['inherit_members' => true]);
    Member::query()->where('project_id', $child->id)->where('user_id', $mixed->id)->firstOrFail()->roles()->attach($childRole);

    $child->update(['inherit_members' => false]);

    $authorization = app(AuthorizationService::class);

    expect(Member::query()->where('project_id', $child->id)->where('user_id', $inheritedOnly->id)->exists())->toBeFalse()
        ->and(inheritanceRoleRows($child, $mixed))->toBe([$childRole->id => null])
        ->and($authorization->can($inheritedOnly, 'view_project', $child))->toBeFalse()
        ->and($authorization->can($mixed, 'manage_members', $child))->toBeFalse();
});

test('a role the subproject already gives directly is not copied a second time', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $user = User::factory()->create();
    $shared = Role::factory()->create();
    $other = Role::factory()->create();
    $parentMember = addInheritanceMember($parent, $user, [$shared, $other]);
    addInheritanceMember($child, $user, [$shared]);

    $child->update(['inherit_members' => true]);

    expect(inheritanceRoleRows($child, $user))->toBe([
        $shared->id => null,
        $other->id => sourceRowId($parentMember, $other),
    ]);
});

test('a grandchild inherits through a child that inherits, and loses it when the child stops', function () {
    $root = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $root->id]);
    $grandchild = Project::factory()->create(['parent_id' => $child->id]);
    $user = User::factory()->create();
    $role = Role::factory()->create();
    addInheritanceMember($root, $user, [$role]);

    $grandchild->update(['inherit_members' => true]);

    expect(inheritanceRoleRows($grandchild, $user))->toBe([]);

    $child->update(['inherit_members' => true]);

    $childMember = Member::query()->where('project_id', $child->id)->where('user_id', $user->id)->firstOrFail();

    expect(inheritanceRoleRows($grandchild, $user))->toBe([$role->id => sourceRowId($childMember, $role)]);

    $child->update(['inherit_members' => false]);

    expect(inheritanceRoleRows($child, $user))->toBe([])
        ->and(inheritanceRoleRows($grandchild, $user))->toBe([]);
});

test('the project form saves the inherit members checkbox', function () {
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create();
    $user = User::factory()->create();
    addInheritanceMember($parent, $user, [Role::factory()->create()]);
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $child->trackers()->attach(Tracker::factory()->create());

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $child])
        ->assertSee('メンバーを継承')
        ->assertSet('inherit_members', false)
        ->set('inherit_members', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($child->refresh()->inherit_members)->toBeTrue()
        ->and($child->users()->pluck('users.id')->all())->toBe([$user->id]);
});

test('someone who cannot see the parent cannot turn on inherit_members', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $outsider = User::factory()->create();
    addInheritanceMember($parent, User::factory()->create(), [Role::factory()->create()]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    $child->trackers()->attach(Tracker::factory()->create());
    addInheritanceMember($child, $outsider, [Role::factory()->create(['permissions' => ['view_project', 'edit_project']])]);

    Livewire::actingAs($outsider)
        ->test('projects.form', ['project' => $child])
        ->set('inherit_members', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($child->refresh()->inherit_members)->toBeFalse()
        ->and($child->members()->count())->toBe(1);
});

test('adding a member to the parent adds it to every inheriting subproject down the chain', function () {
    [$parent, $child, $grandchild, $admin] = inheritingTree();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues']]);

    Livewire::actingAs($admin)->test('projects.members', ['project' => $parent])
        ->set('selectedUserId', $user->id)
        ->set('roleIds', [$role->id])
        ->call('addMember')
        ->assertHasNoErrors();

    $parentMember = memberIn($parent, $user);
    $childMember = memberIn($child, $user);

    expect(inheritanceRoleRows($child, $user))->toBe([$role->id => sourceRowId($parentMember, $role)])
        ->and(inheritanceRoleRows($grandchild, $user))->toBe([$role->id => sourceRowId($childMember, $role)])
        ->and(app(AuthorizationService::class)->can($user, 'view_issues', $grandchild))->toBeTrue();
});

test('a subproject that does not inherit members gets nothing from its parent', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues']]);

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.members', ['project' => $parent])
        ->set('selectedUserId', $user->id)
        ->set('roleIds', [$role->id])
        ->call('addMember');

    expect(memberIn($child, $user))->toBeNull()
        ->and(app(AuthorizationService::class)->can($user, 'view_issues', $child))->toBeFalse();
});

test('changing a member roles in the parent changes them in the subprojects', function () {
    [$parent, $child, $grandchild, $admin] = inheritingTree();
    $user = User::factory()->create();
    $developer = Role::factory()->create(['permissions' => ['view_project', 'edit_issues']]);
    $reporter = Role::factory()->create(['permissions' => ['view_project']]);
    $parentMember = addInheritanceMember($parent, $user, [$developer]);
    app(MemberInheritance::class)->sync($child);

    Livewire::actingAs($admin)->test('projects.members', ['project' => $parent])
        ->call('editMember', $parentMember->id)
        ->set('roleIds', [$reporter->id])
        ->call('addMember')
        ->assertHasNoErrors();

    expect(array_keys(inheritanceRoleRows($child, $user)))->toBe([$reporter->id])
        ->and(array_keys(inheritanceRoleRows($grandchild, $user)))->toBe([$reporter->id])
        ->and(app(AuthorizationService::class)->can($user, 'edit_issues', $grandchild))->toBeFalse();
});

test('removing a member from the parent revokes it in the subprojects but keeps roles given there', function () {
    [$parent, $child, $grandchild, $admin] = inheritingTree();
    $inheritedOnly = User::factory()->create();
    $mixed = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues']]);
    $childRole = Role::factory()->create(['permissions' => ['view_project']]);
    $inheritedOnlyMember = addInheritanceMember($parent, $inheritedOnly, [$role]);
    $mixedMember = addInheritanceMember($parent, $mixed, [$role]);
    app(MemberInheritance::class)->sync($child);
    memberIn($child, $mixed)->syncDirectRoles([$childRole->id]);

    $component = Livewire::actingAs($admin)->test('projects.members', ['project' => $parent]);
    $component->call('removeMember', $inheritedOnlyMember->id);
    $component->call('removeMember', $mixedMember->id);

    $authorization = app(AuthorizationService::class);

    expect(memberIn($child, $inheritedOnly))->toBeNull()
        ->and(memberIn($grandchild, $inheritedOnly))->toBeNull()
        ->and($authorization->can($inheritedOnly, 'view_issues', $grandchild))->toBeFalse()
        ->and(inheritanceRoleRows($child, $mixed))->toBe([$childRole->id => null])
        ->and(array_keys(inheritanceRoleRows($grandchild, $mixed)))->toBe([$childRole->id])
        ->and($authorization->can($mixed, 'view_issues', $child))->toBeFalse();
});

test('the REST API propagates membership changes made in the parent', function () {
    [$parent, $child, $grandchild, $admin] = inheritingTree();
    $user = User::factory()->create();
    $developer = Role::factory()->create();
    $reporter = Role::factory()->create();

    Passport::actingAs($admin);

    $membershipId = $this->postJson("/api/v1/projects/{$parent->id}/memberships", ['user_id' => $user->id, 'role_ids' => [$developer->id]])
        ->assertCreated()->json('data.id');

    expect(array_keys(inheritanceRoleRows($grandchild, $user)))->toBe([$developer->id]);

    $this->putJson("/api/v1/memberships/{$membershipId}", ['role_ids' => [$reporter->id]])->assertOk();

    expect(array_keys(inheritanceRoleRows($grandchild, $user)))->toBe([$reporter->id]);

    $this->deleteJson("/api/v1/memberships/{$membershipId}")->assertNoContent();

    expect(memberIn($child, $user))->toBeNull()->and(memberIn($grandchild, $user))->toBeNull();
});

test('the administrator membership panel propagates changes made in the parent', function () {
    [$parent, $child, , $admin] = inheritingTree();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);

    $form = Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('membershipProjectId', $parent->id)
        ->set('membershipRoleIds', [$role->id])
        ->call('saveMembership')
        ->assertHasNoErrors();

    expect(array_keys(inheritanceRoleRows($child, $user)))->toBe([$role->id]);

    $form->call('removeMembership', memberIn($parent, $user)->id);

    expect(memberIn($child, $user))->toBeNull();
});

test('inherited members cannot be removed from the subproject and their inherited roles cannot be taken away there', function () {
    [$parent, $child, , $admin] = inheritingTree();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    $childRole = Role::factory()->create(['permissions' => ['view_project']]);
    addInheritanceMember($parent, $user, [$role]);
    app(MemberInheritance::class)->sync($child);
    $childMember = memberIn($child, $user);

    $component = Livewire::actingAs($admin)->test('projects.members', ['project' => $child])
        ->assertSee('親プロジェクトから継承')
        ->assertDontSeeHtml("removeMember({$childMember->id})");

    $component->call('removeMember', $childMember->id)->assertForbidden();

    Livewire::actingAs($admin)->test('projects.members', ['project' => $child])
        ->call('editMember', $childMember->id)
        ->assertSet('roleIds', [])
        ->set('roleIds', [$childRole->id])
        ->call('addMember')
        ->assertHasNoErrors();

    expect(inheritanceRoleRows($child, $user))->toBe([
        $role->id => sourceRowId(memberIn($parent, $user), $role),
        $childRole->id => null,
    ]);

    Livewire::actingAs($admin)->test('projects.members', ['project' => $child])
        ->call('editMember', $childMember->id)
        ->set('roleIds', [])
        ->call('addMember')
        ->assertHasNoErrors();

    expect(array_keys(inheritanceRoleRows($child, $user)))->toBe([$role->id]);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->call('removeMembership', $childMember->id)
        ->assertForbidden();

    expect(memberIn($child, $user))->not->toBeNull();
});

test('the REST API refuses to delete an inherited membership and keeps its inherited roles on update', function () {
    [$parent, $child, , $admin] = inheritingTree();
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $otherRole = Role::factory()->create();
    addInheritanceMember($parent, $user, [$role]);
    app(MemberInheritance::class)->sync($child);
    $childMember = memberIn($child, $user);

    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/memberships/{$childMember->id}")->assertUnprocessable();

    $this->putJson("/api/v1/memberships/{$childMember->id}", ['role_ids' => [$otherRole->id]])->assertOk();

    expect(inheritanceRoleRows($child, $user))->toBe([
        $role->id => sourceRowId(memberIn($parent, $user), $role),
        $otherRole->id => null,
    ]);

    $this->putJson("/api/v1/memberships/{$childMember->id}", ['role_ids' => []])->assertOk();

    expect(array_keys(inheritanceRoleRows($child, $user)))->toBe([$role->id])
        ->and(memberIn($child, $user))->not->toBeNull();
});

test('a manager of the subproject alone cannot remove an inherited member', function () {
    [$parent, $child] = inheritingTree();
    $user = User::factory()->create();
    addInheritanceMember($parent, $user, [Role::factory()->create()]);
    $manager = User::factory()->create();
    addInheritanceMember($child, $manager, [Role::factory()->create(['permissions' => ['view_project', 'manage_members'], 'all_roles_managed' => true])]);
    app(MemberInheritance::class)->sync($child);

    Livewire::actingAs($manager)->test('projects.members', ['project' => $child])
        ->call('removeMember', memberIn($child, $user)->id)
        ->assertForbidden();

    expect(memberIn($child, $user))->not->toBeNull();
});

test('removing a role given directly in the subproject brings back the same role inherited from the parent', function () {
    [$parent, $child, , $admin] = inheritingTree();
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $parentMember = addInheritanceMember($parent, $user, [$role]);
    addInheritanceMember($child, $user, [$role]);
    app(MemberInheritance::class)->sync($child);

    expect(inheritanceRoleRows($child, $user))->toBe([$role->id => null]);

    memberIn($child, $user)->syncDirectRoles([]);

    expect(inheritanceRoleRows($child, $user))->toBe([$role->id => sourceRowId($parentMember, $role)]);
});

test('moving an inheriting project swaps the old parent members for the new parent ones', function () {
    $oldParent = Project::factory()->create(['is_public' => false]);
    $newParent = Project::factory()->create(['is_public' => false]);
    $project = Project::factory()->create(['parent_id' => $oldParent->id, 'is_public' => false, 'inherit_members' => true]);
    $project->trackers()->attach(Tracker::factory()->create());
    $grandchild = Project::factory()->create(['parent_id' => $project->id, 'is_public' => false, 'inherit_members' => true]);
    $oldUser = User::factory()->create();
    $newUser = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    addInheritanceMember($oldParent, $oldUser, [$role]);
    addInheritanceMember($newParent, $newUser, [$role]);
    app(MemberInheritance::class)->sync($project);

    expect(memberIn($grandchild, $oldUser))->not->toBeNull();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('projects.form', ['project' => $project])
        ->set('parent_id', $newParent->id)
        ->call('save')
        ->assertHasNoErrors();

    $authorization = app(AuthorizationService::class);

    expect(memberIn($project, $oldUser))->toBeNull()
        ->and(memberIn($grandchild, $oldUser))->toBeNull()
        ->and($authorization->can($oldUser, 'view_project', $project))->toBeFalse()
        ->and(memberIn($project, $newUser))->not->toBeNull()
        ->and(memberIn($grandchild, $newUser))->not->toBeNull();
});

test('moving an inheriting project to the top level through the REST API removes the inherited members', function () {
    [$parent, $child, $grandchild, $admin] = inheritingTree();
    $user = User::factory()->create();
    addInheritanceMember($parent, $user, [Role::factory()->create()]);
    app(MemberInheritance::class)->sync($child);

    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$child->id}", ['parent_id' => null])->assertSuccessful();

    expect($child->refresh()->parent_id)->toBeNull()
        ->and(memberIn($child, $user))->toBeNull()
        ->and(memberIn($grandchild, $user))->toBeNull();
});

test('a subproject created with inherit members starts with the parent members, next to the creator default role', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $creator = User::factory()->create();
    $colleague = User::factory()->create();
    $parentRole = Role::factory()->create(['permissions' => ['view_project', 'add_subprojects']]);
    $defaultRole = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);
    Setting::set('new_project_user_role_id', $defaultRole->id);
    $creatorParentMember = addInheritanceMember($parent, $creator, [$parentRole]);
    addInheritanceMember($parent, $colleague, [$parentRole]);

    Livewire::actingAs($creator)->withQueryParams(['parent_id' => $parent->id])
        ->test('projects.form')
        ->set('name', 'Inheriting child')
        ->set('identifier', 'inheriting-child')
        ->set('inherit_members', true)
        ->set('trackerIds', [Tracker::factory()->create()->id])
        ->call('save')
        ->assertHasNoErrors();

    $child = Project::query()->where('identifier', 'inheriting-child')->firstOrFail();

    expect($child->inherit_members)->toBeTrue()
        ->and(inheritanceRoleRows($child, $creator))->toBe([
            $parentRole->id => sourceRowId($creatorParentMember, $parentRole),
            $defaultRole->id => null,
        ])
        ->and(array_keys(inheritanceRoleRows($child, $colleague)))->toBe([$parentRole->id]);
});

test('a non-administrator member of the parent is warned before unticking "inherit members"', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $manager = User::factory()->create();
    addInheritanceMember($parent, $manager, [Role::factory()->create(['permissions' => ['view_project', 'edit_project']])]);
    $child = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false, 'inherit_members' => true]);
    $plain = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    addInheritanceMember($plain, $manager, [Role::factory()->create(['permissions' => ['view_project', 'edit_project']])]);

    $outsider = User::factory()->create();
    addInheritanceMember($child, $outsider, [Role::factory()->create(['permissions' => ['view_project', 'edit_project']])]);

    Livewire::actingAs($manager)->test('projects.form', ['project' => $child])
        ->assertSeeHtml('data-confirm-leaving-inheritance');
    Livewire::actingAs(User::factory()->admin()->create())->test('projects.form', ['project' => $child])
        ->assertDontSeeHtml('data-confirm-leaving-inheritance');
    Livewire::actingAs($manager)->test('projects.form', ['project' => $plain])
        ->assertDontSeeHtml('data-confirm-leaving-inheritance');
    Livewire::actingAs($outsider)->test('projects.form', ['project' => $child])
        ->assertDontSeeHtml('data-confirm-leaving-inheritance');
});
