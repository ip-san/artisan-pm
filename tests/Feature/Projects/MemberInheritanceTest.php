<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Facades\DB;
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
