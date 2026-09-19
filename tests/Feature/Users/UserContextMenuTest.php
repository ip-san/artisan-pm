<?php

use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\User;
use Livewire\Livewire;

test('right-clicking an unselected user selects only that user, a selected one keeps the selection', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();

    $list = Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $a->id])->call('openContextMenu', $b->id);
    expect($list->get('selected'))->toBe([(string) $b->id]);

    $list->set('selected', [(string) $a->id, (string) $b->id])->call('openContextMenu', $a->id);
    expect($list->get('selected'))->toBe([(string) $a->id, (string) $b->id]);
});

test('only administrators reach the users list menu', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    Livewire::actingAs($user)->test('users.index')->assertForbidden();
});

test('the menu can lock a selection and unlock it again', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();

    $list = Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $a->id, (string) $b->id])->call('bulkSetLocked', true);
    expect($a->fresh()->status)->toBe(UserStatus::Locked)->and($b->fresh()->status)->toBe(UserStatus::Locked);
    expect($list->get('selected'))->toBe([]);

    $list->set('selected', [(string) $a->id])->assertSee('ロック解除', false)->call('bulkSetLocked', false);
    expect($a->fresh()->status)->toBe(UserStatus::Active)->and($b->fresh()->status)->toBe(UserStatus::Locked);
});

test('the signed-in admin is never locked or deleted through the menu', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $admin->id, (string) $other->id])->call('bulkSetLocked', true);
    expect($admin->fresh()->status)->toBe(UserStatus::Active)->and($other->fresh()->status)->toBe(UserStatus::Locked);

    Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $admin->id, (string) $other->id])->call('bulkDelete');
    expect($admin->fresh()->status)->toBe(UserStatus::Active)->and($other->fresh()->status)->toBe(UserStatus::Deleted);
});

test('the last active administrator cannot be deleted through the menu', function () {
    $admin = User::factory()->admin()->create();
    $lockedAdmin = User::factory()->admin()->create(['status' => UserStatus::Locked]);
    $second = User::factory()->admin()->create();

    // $second is the only other active admin besides the signed-in one, so
    // deleting it is fine; deleting the locked admin never counted anyway.
    Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $second->id])->call('bulkDelete');
    expect($second->fresh()->status)->toBe(UserStatus::Deleted);

    $actor = User::factory()->admin()->create();
    $actor->update(['status' => UserStatus::Locked]);
    Livewire::actingAs($actor)->test('users.index')->set('selected', [(string) $admin->id])->call('bulkDelete');
    expect($admin->fresh()->status)->toBe(UserStatus::Active)->and($lockedAdmin->fresh()->status)->toBe(UserStatus::Locked);
});

test('the menu adds the selection to a group and removes it again', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $group = Group::factory()->create(['name' => 'Developers']);

    $list = Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $a->id, (string) $b->id])->call('addToGroup', $group->id);
    expect($group->users()->pluck('users.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $list->set('selected', [(string) $a->id, (string) $b->id])->assertSee('グループから外す')->call('removeFromGroup', $group->id);
    expect($group->users()->count())->toBe(0);
});

test('group removal is only offered for groups every selected user belongs to', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $group = Group::factory()->create();
    $group->users()->attach($a);

    Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $a->id, (string) $b->id])->assertDontSee('グループから外す');
    Livewire::actingAs($admin)->test('users.index')->set('selected', [(string) $a->id])->assertSee('グループから外す');
});
