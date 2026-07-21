<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
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
