<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Livewire\Livewire;

test('an admin can add a member with roles by email', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.members', ['project' => $project])
        ->set('email', $user->email)
        ->set('roleIds', [$role->id])
        ->call('addMember');

    $member = Member::where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();
    expect($member->roles->pluck('id')->all())->toBe([$role->id]);
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

    expect($component->get('email'))->toBe($user->email)
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
        ->assertSet('email', '')
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
