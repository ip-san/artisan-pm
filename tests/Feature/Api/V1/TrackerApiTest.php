<?php

use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/trackers')->assertUnauthorized();
});

test('any authenticated user can list all trackers, ordered by position', function () {
    $user = User::factory()->create();
    // Sortable::setHighestOrderNumber() overwrites any explicit `position`
    // passed to create() with max+1, so creation order — not an explicit
    // position value — is what determines the resulting order here.
    Tracker::factory()->create(['name' => 'First']);
    Tracker::factory()->create(['name' => 'Second']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/trackers');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names->all())->toBe(['First', 'Second']);
});

test('any authenticated user can show a single tracker', function () {
    $user = User::factory()->create();
    $tracker = Tracker::factory()->create(['name' => 'Bug']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/trackers/{$tracker->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $tracker->id)
        ->assertJsonPath('data.name', 'Bug');
});

test('a member of no projects can still list trackers, matching Redmine\'s own unscoped index', function () {
    $user = User::factory()->create();
    Tracker::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/trackers')->assertOk();
});
