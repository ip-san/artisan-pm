<?php

use App\Models\Group;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/groups')->assertUnauthorized();
});

test('a non-admin cannot list groups', function () {
    $user = User::factory()->create();
    Group::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/groups')->assertForbidden();
});

test('an admin can list groups', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create(['name' => 'Developers']);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/groups');

    $response->assertOk()->assertJsonPath('data.0.id', $group->id);
});

test('an admin can show a single group including its member ids', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create(['name' => 'Developers']);
    $member = User::factory()->create();
    $group->users()->attach($member);

    Passport::actingAs($admin);

    $this->getJson("/api/v1/groups/{$group->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $group->id)
        ->assertJsonPath('data.name', 'Developers')
        ->assertJsonPath('data.user_ids', [$member->id]);
});

test('a non-admin cannot show a group', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/groups/{$group->id}")->assertForbidden();
});

test('creating a group via the api requires admin', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->postJson('/api/v1/groups', ['name' => 'Should be forbidden'])->assertForbidden();
});

test('an admin can create a group with members via the api', function () {
    $admin = User::factory()->admin()->create();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/v1/groups', [
        'name' => 'Created via API',
        'user_ids' => [$memberA->id, $memberB->id],
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'Created via API');

    $group = Group::where('name', 'Created via API')->firstOrFail();
    expect($group->users->pluck('id')->sort()->values()->all())->toBe(collect([$memberA->id, $memberB->id])->sort()->values()->all());
});

test('a group name must be unique', function () {
    $admin = User::factory()->admin()->create();
    Group::factory()->create(['name' => 'Duplicate']);

    Passport::actingAs($admin);

    $this->postJson('/api/v1/groups', ['name' => 'Duplicate'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('updating a group via the api requires admin', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/groups/{$group->id}", ['name' => 'Renamed'])->assertForbidden();
});

test('an admin can rename a group and replace its members via the api', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create(['name' => 'Old name']);
    $oldMember = User::factory()->create();
    $newMember = User::factory()->create();
    $group->users()->attach($oldMember);

    Passport::actingAs($admin);

    $this->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'New name',
        'user_ids' => [$newMember->id],
    ])->assertOk()->assertJsonPath('data.name', 'New name');

    $group->refresh();
    expect($group->name)->toBe('New name')
        ->and($group->users->pluck('id')->all())->toBe([$newMember->id]);
});

test('an admin can delete a group via the api', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();

    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/groups/{$group->id}")->assertNoContent();

    expect(Group::find($group->id))->toBeNull();
});

test('deleting a group via the api requires admin', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/groups/{$group->id}")->assertForbidden();

    expect(Group::find($group->id))->not->toBeNull();
});
