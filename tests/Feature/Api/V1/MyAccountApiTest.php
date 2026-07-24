<?php

use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/my/account')->assertUnauthorized();
});

test('any authenticated user can view their own account', function () {
    $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    Passport::actingAs($user);

    $this->getJson('/api/v1/my/account')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.email', 'jane@example.com');
});

test('the response never leaks the password hash or api key', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/my/account');

    expect($response->json('data'))
        ->not->toHaveKey('password')
        ->not->toHaveKey('api_key')
        ->not->toHaveKey('remember_token');
});

test('a non-admin can update their own name and email', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new@example.com');

    expect($user->fresh())
        ->name->toBe('New Name')
        ->email->toBe('new@example.com');
});

test('a partial update only changes the submitted field', function () {
    $user = User::factory()->create(['name' => 'Original Name', 'email' => 'original@example.com']);

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['name' => 'Updated Name'])->assertOk();

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->email->toBe('original@example.com');
});

test('updating the email to one already taken by another user is rejected', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['email' => 'taken@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh()->email)->toBe('mine@example.com');
});

test('a user can keep their own current email unchanged without a uniqueness conflict', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['name' => 'Renamed', 'email' => 'mine@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'mine@example.com');
});

test('a user cannot grant themselves admin via the account update payload', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['name' => 'Still Not Admin', 'is_admin' => true])->assertOk();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('a user only ever updates their own account, never another user\'s', function () {
    $user = User::factory()->create(['name' => 'Me']);
    $other = User::factory()->create(['name' => 'Someone Else']);

    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['name' => 'Updated Me'])->assertOk();

    expect($user->fresh()->name)->toBe('Updated Me')
        ->and($other->fresh()->name)->toBe('Someone Else');
});
