<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\Group;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<string, array{operator: string, values: array<int, mixed>}>  $filters
 * @return array<int, string> names in list order
 */
function userListNames(User $admin, array $filters, ?string $sortKey = null, string $direction = 'asc'): array
{
    $list = Livewire::actingAs($admin)->test('users.index');

    foreach ($filters as $key => $filter) {
        $list->set('activeFilterKeys', [...$list->get('activeFilterKeys'), $key])
            ->set("filterOperators.{$key}", $filter['operator'])
            ->set("filterValues.{$key}", $filter['values']);
    }

    if ($sortKey !== null) {
        $list->set('sortKey', $sortKey)->set('sortDirection', $direction);
    }

    return $list->get('users')->pluck('name')->all();
}

test('the list filters by status', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => 'Alice']);
    User::factory()->create(['name' => 'Locked One', 'status' => UserStatus::Locked]);

    expect(userListNames($admin, ['status' => ['operator' => '=', 'values' => ['locked']]]))->toBe(['Locked One'])
        ->and(userListNames($admin, ['status' => ['operator' => '!', 'values' => ['locked']]]))->toBe(['Admin', 'Alice']);
});

test('a filter with an operator but no value yet matches everything instead of failing', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => 'Alice']);

    expect(userListNames($admin, ['created_at' => ['operator' => '>=', 'values' => []]]))->toBe(['Admin', 'Alice'])
        ->and(userListNames($admin, ['created_at' => ['operator' => '><', 'values' => ['2026-01-01']]]))->toBe(['Admin', 'Alice']);
});

test('the list filters by text on name, login and email', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => 'Alice Smith', 'login' => 'asmith', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Jones', 'login' => 'bjones', 'email' => 'bob@corp.test']);

    expect(userListNames($admin, ['name' => ['operator' => '~', 'values' => ['smith']]]))->toBe(['Alice Smith'])
        ->and(userListNames($admin, ['login' => ['operator' => '=', 'values' => ['bjones']]]))->toBe(['Bob Jones'])
        ->and(userListNames($admin, ['email' => ['operator' => '~', 'values' => ['corp.test']]]))->toBe(['Bob Jones']);
});

test('the list filters by group membership, including none', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    $member = User::factory()->create(['name' => 'Member']);
    User::factory()->create(['name' => 'Loner']);
    $group = Group::factory()->create();
    $group->users()->attach($member);

    expect(userListNames($admin, ['group_id' => ['operator' => '=', 'values' => [(string) $group->id]]]))->toBe(['Member'])
        ->and(userListNames($admin, ['group_id' => ['operator' => '!', 'values' => [(string) $group->id]]]))->toBe(['Admin', 'Loner'])
        ->and(userListNames($admin, ['group_id' => ['operator' => 'empty', 'values' => []]]))->toBe(['Admin', 'Loner'])
        ->and(userListNames($admin, ['group_id' => ['operator' => 'not_empty', 'values' => []]]))->toBe(['Member']);
});

test('the list filters by authentication method and by administrator', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    $source = AuthSource::factory()->create();
    User::factory()->create(['name' => 'Ldap User', 'auth_source_id' => $source->id]);
    User::factory()->create(['name' => 'Local User']);

    expect(userListNames($admin, ['auth_source_id' => ['operator' => '=', 'values' => [(string) $source->id]]]))->toBe(['Ldap User'])
        ->and(userListNames($admin, ['auth_source_id' => ['operator' => 'empty', 'values' => []]]))->toBe(['Admin', 'Local User'])
        ->and(userListNames($admin, ['is_admin' => ['operator' => '=', 'values' => ['1']]]))->toBe(['Admin']);
});

test('the list filters by registration date', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin', 'created_at' => '2026-01-01']);
    User::factory()->create(['name' => 'Old', 'created_at' => '2025-01-01']);
    User::factory()->create(['name' => 'New', 'created_at' => '2026-06-01']);

    expect(userListNames($admin, ['created_at' => ['operator' => '>=', 'values' => ['2026-02-01']]]))->toBe(['New']);
});

test('the list sorts by a column in either direction and ignores an unknown key', function () {
    $admin = User::factory()->admin()->create(['name' => 'Mid', 'login' => 'mid']);
    User::factory()->create(['name' => 'Zed', 'login' => 'aaa']);
    User::factory()->create(['name' => 'Alpha', 'login' => 'zzz']);

    expect(userListNames($admin, [], 'login'))->toBe(['Zed', 'Mid', 'Alpha'])
        ->and(userListNames($admin, [], 'login', 'desc'))->toBe(['Alpha', 'Mid', 'Zed'])
        ->and(userListNames($admin, [], 'password'))->toBe(['Alpha', 'Mid', 'Zed']);
});

test('the chosen columns drive the table, and unknown columns fall back to the defaults', function () {
    $admin = User::factory()->admin()->create();

    $list = Livewire::actingAs($admin)->test('users.index');
    expect($list->get('visibleColumns'))->toBe(['name', 'email', 'is_admin', 'status', 'auth_source_id']);

    $list->set('columns', ['login']);
    expect($list->get('visibleColumns'))->toBe(['login']);
    $list->assertSee('wire:key="user-heading-login"', false)->assertDontSee('wire:key="user-heading-email"', false);

    $list->set('columns', ['nonsense']);

    expect($list->get('visibleColumns'))->toBe(['name', 'email', 'is_admin', 'status', 'auth_source_id']);
});

test('the list exports its filtered rows and chosen columns as CSV', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin', 'login' => 'admin', 'email' => 'admin@example.com']);
    User::factory()->create(['name' => 'Alice', 'login' => 'alice', 'email' => 'alice@example.com']);

    Livewire::actingAs($admin)->test('users.index')
        ->set('columns', ['name', 'login'])
        ->set('activeFilterKeys', ['name'])->set('filterOperators.name', '~')->set('filterValues.name', ['Ali'])
        ->call('exportCsv')
        ->assertFileDownloaded('users.csv', "\xEF\xBB\xBF".csvRow(['名前', 'ログインID']).csvRow(['Alice', 'alice']));
});

test('only administrators can export the list', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('users.index')->assertForbidden();
});

test('a deleted account never shows up whatever the filters say', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => 'Gone', 'status' => UserStatus::Deleted]);

    expect(userListNames($admin, ['status' => ['operator' => '=', 'values' => ['deleted']]]))->toBe([]);
});

test('contains ignores case and treats % and _ literally', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => '100% Sure']);
    User::factory()->create(['name' => 'Plain']);

    expect(userListNames($admin, ['name' => ['operator' => '~', 'values' => ['SURE']]]))->toBe(['100% Sure'])
        ->and(userListNames($admin, ['name' => ['operator' => '~', 'values' => ['%']]]))->toBe(['100% Sure'])
        ->and(userListNames($admin, ['name' => ['operator' => '!~', 'values' => ['sure']]]))->toBe(['Admin', 'Plain']);
});
