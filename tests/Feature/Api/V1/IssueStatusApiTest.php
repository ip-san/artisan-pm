<?php

use App\Models\IssueStatus;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/issue_statuses')->assertUnauthorized();
});

test('any authenticated user can list all issue statuses, ordered by position', function () {
    $user = User::factory()->create();
    // Sortable::setHighestOrderNumber() overwrites any explicit `position`
    // passed to create() with max+1, so creation order — not an explicit
    // position value — is what determines the resulting order here.
    IssueStatus::factory()->create(['name' => 'First']);
    IssueStatus::factory()->create(['name' => 'Second']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/issue_statuses');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names->all())->toBe(['First', 'Second']);
});

test('any authenticated user can show a single issue status', function () {
    $user = User::factory()->create();
    $status = IssueStatus::factory()->closed()->create(['name' => 'Closed']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issue_statuses/{$status->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $status->id)
        ->assertJsonPath('data.name', 'Closed')
        ->assertJsonPath('data.is_closed', true);
});

test('a member of no projects can still list issue statuses, matching Redmine\'s own unscoped index', function () {
    $user = User::factory()->create();
    IssueStatus::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/issue_statuses')->assertOk();
});
