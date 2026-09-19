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

test('an admin can add users to a group by user_id or user_ids', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    [$a, $b, $c] = User::factory()->count(3)->create()->all();

    Passport::actingAs($admin);

    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_id' => $a->id])->assertNoContent();
    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_ids' => [$b->id, (string) $c->id]])->assertNoContent();

    expect($group->users()->pluck('users.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id, $c->id])->sort()->values()->all());
});

test('adding skips users already in the group and unknown ids, and fails when nothing could be added', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $newcomer = User::factory()->create();
    $group->users()->attach($member);

    Passport::actingAs($admin);

    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_ids' => [$member->id, $newcomer->id, 999999]])->assertNoContent();
    expect($group->users()->count())->toBe(2);

    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_id' => $member->id])->assertUnprocessable();
    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_id' => 999999])->assertUnprocessable();
    $this->postJson("/api/v1/groups/{$group->id}/users", [])->assertUnprocessable();
    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_ids' => [['nested'], 'abc', -3, 0]])->assertUnprocessable();
    expect($group->users()->count())->toBe(2);
});

test('an admin can remove members with user_id, user_ids or the classic user route', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
    $group->users()->attach([$a->id, $b->id, $c->id, $d->id]);

    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/groups/{$group->id}/users", ['user_id' => $a->id])->assertNoContent();
    $this->deleteJson("/api/v1/groups/{$group->id}/users", ['user_ids' => [$b->id, $c->id]])->assertNoContent();
    $this->deleteJson("/api/v1/groups/{$group->id}/users/{$d->id}")->assertNoContent();

    expect($group->users()->count())->toBe(0);
});

test('removing users who are not members is a 404 and touches nothing', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $group->users()->attach($member);

    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/groups/{$group->id}/users", ['user_id' => $outsider->id])->assertNotFound();
    $this->deleteJson("/api/v1/groups/{$group->id}/users")->assertNotFound();
    $this->deleteJson("/api/v1/groups/{$group->id}/users/{$outsider->id}")->assertNotFound();
    expect($group->users()->count())->toBe(1);
});

test('only an admin can change group membership through the api', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->users()->attach($member);

    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_id' => $user->id])->assertUnauthorized();

    Passport::actingAs($user);

    $this->postJson("/api/v1/groups/{$group->id}/users", ['user_id' => $user->id])->assertForbidden();
    $this->deleteJson("/api/v1/groups/{$group->id}/users", ['user_id' => $member->id])->assertForbidden();
    $this->deleteJson("/api/v1/groups/{$group->id}/users/{$member->id}")->assertForbidden();
    expect($group->users()->count())->toBe(1);
});
