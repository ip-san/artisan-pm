<?php

use App\Enums\UserStatus;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

test('a non-admin cannot list users', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/users')->assertForbidden();
});

test('an admin can list users', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Alice']);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($other->id, $admin->id);
});

test('an admin can filter users by status', function () {
    $admin = User::factory()->admin()->create();
    $locked = User::factory()->create(['status' => UserStatus::Locked]);
    User::factory()->create(['status' => UserStatus::Active]);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users?status=locked');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($locked->id)->not->toContain($admin->id);
});

test('an admin can search users by name or email', function () {
    $admin = User::factory()->admin()->create();
    $match = User::factory()->create(['name' => 'Zephyr Match', 'email' => 'zephyr@example.com']);
    User::factory()->create(['name' => 'Someone Else', 'email' => 'someone@example.com']);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users?name=zephyr');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($match->id);
});

test('an invalid status filter value is rejected', function () {
    $admin = User::factory()->admin()->create();

    Passport::actingAs($admin);

    $this->getJson('/api/v1/users?status=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('an admin can show a single user', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Passport::actingAs($admin);

    $this->getJson("/api/v1/users/{$other->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $other->id)
        ->assertJsonPath('data.name', 'Bob')
        ->assertJsonPath('data.email', 'bob@example.com');
});

test('the user response never leaks the password hash or api key', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->getJson("/api/v1/users/{$other->id}");

    expect($response->json('data'))
        ->not->toHaveKey('password')
        ->not->toHaveKey('api_key')
        ->not->toHaveKey('remember_token');
});

test('a non-admin cannot show a single user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/users/{$other->id}")->assertForbidden();
});
