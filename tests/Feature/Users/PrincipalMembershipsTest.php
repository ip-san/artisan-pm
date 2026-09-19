<?php

use App\Enums\ProjectStatus;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function membershipRole(string $name = 'Developer'): Role
{
    return Role::factory()->create(['name' => $name, 'permissions' => ['view_project']]);
}

test('an administrator adds a user to a project with roles from the user page', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Alpha']);
    $developer = membershipRole();
    $reporter = membershipRole('Reporter');

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->assertSee('所属するプロジェクトはありません')
        ->set('membershipProjectId', $project->id)
        ->set('membershipRoleIds', [$developer->id, $reporter->id])
        ->call('saveMembership')
        ->assertHasNoErrors()
        ->assertSee('Alpha');

    $member = Member::query()->where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();
    expect($member->roles->pluck('name')->all())->toEqualCanonicalizing(['Developer', 'Reporter']);
});

test('roles of an existing membership can be changed and it can be removed', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $developer = membershipRole();
    $manager = membershipRole('Manager');
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($developer);

    $page = Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->call('editMembership', $member->id)
        ->assertSet('membershipProjectId', $project->id)
        ->assertSet('membershipRoleIds', [$developer->id])
        ->set('membershipRoleIds', [$manager->id])
        ->call('saveMembership')
        ->assertHasNoErrors();

    expect($member->fresh()->roles->pluck('name')->all())->toBe(['Manager']);

    $page->call('removeMembership', $member->id);

    expect(Member::query()->whereKey($member->id)->exists())->toBeFalse();
});

test('at least one role is required and the project and roles must be valid', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $archived = Project::factory()->create(['status' => ProjectStatus::Archived->value]);
    $role = membershipRole();

    $form = Livewire::actingAs($admin)->test('users.form', ['user' => $user]);

    $form->set('membershipProjectId', $project->id)->set('membershipRoleIds', [])->call('saveMembership')->assertHasErrors(['membershipRoleIds']);
    $form->set('membershipRoleIds', [99999])->call('saveMembership')->assertHasErrors(['membershipRoleIds.0']);
    $form->set('membershipProjectId', $archived->id)->set('membershipRoleIds', [$role->id])->call('saveMembership')->assertHasErrors(['membershipProjectId']);
    $form->set('membershipProjectId', null)->call('saveMembership')->assertHasErrors(['membershipProjectId']);

    expect(Member::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('projects the user already belongs to are not offered again', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $joined = Project::factory()->create(['name' => 'Joined']);
    $other = Project::factory()->create(['name' => 'Open']);
    Member::factory()->for($joined)->for($user)->create()->roles()->attach(membershipRole());

    $offered = Livewire::actingAs($admin)->test('users.form', ['user' => $user])->get('membershipProjects');

    expect($offered->pluck('name')->all())->toBe(['Open']);

    $role = Role::query()->firstOrFail();
    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('membershipProjectId', $joined->id)
        ->set('membershipRoleIds', [$role->id])
        ->call('saveMembership')
        ->assertHasErrors(['membershipProjectId']);
});

test('only an administrator can change memberships and only the user\'s own rows are reachable', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $project = Project::factory()->create();
    $role = membershipRole();
    $foreign = Member::factory()->for($project)->for($stranger)->create();
    $foreign->roles()->attach($role);

    Livewire::actingAs(User::factory()->create())->test('users.form', ['user' => $user])->assertForbidden();

    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)->test('users.form', ['user' => $user])->call('editMembership', $foreign->id)->assertNotFound();
    Livewire::actingAs($admin)->test('users.form', ['user' => $user])->call('removeMembership', $foreign->id)->assertNotFound();
    expect(Member::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('the panel is not shown when creating a user', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test('users.form')->assertDontSee('data-principal-memberships', false);
});

test('a group gets the same panel and its memberships are group members', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $project = Project::factory()->create();
    $role = membershipRole();

    Livewire::actingAs($admin)->test('groups.form', ['group' => $group])
        ->set('membershipProjectId', $project->id)
        ->set('membershipRoleIds', [$role->id])
        ->call('saveMembership')
        ->assertHasNoErrors();

    $member = Member::query()->where('project_id', $project->id)->where('group_id', $group->id)->firstOrFail();
    expect($member->user_id)->toBeNull()->and($member->roles->pluck('id')->all())->toBe([$role->id]);
});
