<?php

use App\Models\Setting;
use App\Models\User;
use Laravel\Passport\Passport;

test('a request with no credentials at all is rejected', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

test('an X-Redmine-API-Key header authenticates the request', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->getJson('/api/v1/projects')
        ->assertOk();
});

test('a key query parameter authenticates the request', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $this->getJson("/api/v1/projects?key={$key}")->assertOk();
});

test('HTTP Basic auth with the API key as the username authenticates the request', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode("{$key}:")])
        ->getJson('/api/v1/projects')
        ->assertOk();
});

test('an unknown api key is rejected', function () {
    User::factory()->create()->regenerateApiKey();

    $this->withHeaders(['X-Redmine-API-Key' => str_repeat('0', 40)])
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

test('a user with no api key generated cannot authenticate with a blank key', function () {
    User::factory()->create();

    $this->withHeaders(['X-Redmine-API-Key' => ''])
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

test('the resolved user via api key matches the key\'s owner', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $response = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson('/api/v1/user');

    $response->assertOk()->assertJsonPath('id', $user->id);
});

test('regenerating the api key invalidates the previous one', function () {
    $user = User::factory()->create();
    $oldKey = $user->regenerateApiKey();
    $newKey = $user->regenerateApiKey();

    expect($oldKey)->not->toBe($newKey);

    $this->withHeaders(['X-Redmine-API-Key' => $oldKey])
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();

    $this->withHeaders(['X-Redmine-API-Key' => $newKey])
        ->getJson('/api/v1/projects')
        ->assertOk();
});

test('the api_key column never appears in a serialized user response', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $response = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson('/api/v1/user');

    $response->assertOk()->assertJsonMissing(['api_key' => $key]);
});

test('rest_api_enabled off rejects an otherwise-valid API key before authentication is even attempted', function () {
    Setting::set('rest_api_enabled', false);
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->getJson('/api/v1/projects')
        ->assertForbidden();
});

test('rest_api_enabled off rejects a request even with an authenticated Passport session', function () {
    // Passport::actingAs() short-circuits token verification and injects the
    // user straight into the guard; the point here is that the middleware
    // runs ahead of ALL auth mechanisms (api-key and Passport alike), not
    // that a specific token gets rejected.
    Setting::set('rest_api_enabled', false);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->getJson('/api/v1/projects')->assertForbidden();
});

test('rest_api_enabled on (the default in this test suite) accepts a valid request as before', function () {
    $user = User::factory()->create();
    $key = $user->regenerateApiKey();

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->getJson('/api/v1/projects')
        ->assertOk();
});
