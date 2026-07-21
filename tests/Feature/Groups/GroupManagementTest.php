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
        ->set('email', 'member@example.com')
        ->call('addMember');

    expect($group->users()->pluck('users.id'))->toContain($member->id);

    $component->call('removeMember', $member->id);

    expect($group->users()->pluck('users.id'))->not->toContain($member->id);
});

test('an admin can delete a group', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();

    Livewire::actingAs($admin)->test('groups.index')->call('delete', $group->id);

    expect(Group::find($group->id))->toBeNull();
});
