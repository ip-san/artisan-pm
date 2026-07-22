<?php

use App\Models\Group;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create a group', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('groups.form')
        ->set('name', 'Developers')
        ->call('save')
        ->assertRedirect();

    expect(Group::where('name', 'Developers')->exists())->toBeTrue();
});

test('a non-admin cannot access group administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('groups.index')->assertForbidden();
    Livewire::actingAs($user)->test('groups.form')->assertForbidden();
});

test('an admin can add and remove a member from a group', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);

    $component = Livewire::actingAs($admin)
        ->test('groups.form', ['group' => $group])
        ->call('selectUser', $member->id)
        ->call('addMember');

    expect($group->users()->pluck('users.id'))->toContain($member->id);

    $component->call('removeMember', $member->id);

    expect($group->users()->pluck('users.id'))->not->toContain($member->id);
});

test('the group member search dropdown excludes users already in the group', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $matching = User::factory()->create(['name' => 'Carol Example']);
    $existingMember = User::factory()->create(['name' => 'Carol Already In Group']);
    $group->users()->attach($existingMember);

    $candidates = Livewire::actingAs($admin)
        ->test('groups.form', ['group' => $group])
        ->set('userSearch', 'Carol')
        ->get('userCandidates');

    expect($candidates->pluck('id'))->toContain($matching->id)->not->toContain($existingMember->id);
});

test('an admin can delete a group', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();

    Livewire::actingAs($admin)->test('groups.index')->call('delete', $group->id);

    expect(Group::find($group->id))->toBeNull();
});
