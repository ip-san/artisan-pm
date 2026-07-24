<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/enumerations/issue_priorities')->assertUnauthorized();
});

test('any authenticated user can list issue priorities', function () {
    $user = User::factory()->create();
    $priority = Enumeration::factory()->create([
        'type' => EnumerationType::IssuePriority,
        'name' => 'Urgent',
        'is_default' => true,
    ]);

    Passport::actingAs($user);

    $this->getJson('/api/v1/enumerations/issue_priorities')
        ->assertOk()
        ->assertJsonPath('data.0.id', $priority->id)
        ->assertJsonPath('data.0.name', 'Urgent')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.0.active', true);
});

test('any authenticated user can list time entry activities', function () {
    $user = User::factory()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity, 'name' => 'Design']);

    Passport::actingAs($user);

    $this->getJson('/api/v1/enumerations/time_entry_activities')
        ->assertOk()
        ->assertJsonPath('data.0.id', $activity->id)
        ->assertJsonPath('data.0.name', 'Design');
});

test('any authenticated user can list document categories', function () {
    $user = User::factory()->create();
    $category = Enumeration::factory()->create(['type' => EnumerationType::DocumentCategory, 'name' => 'User documentation']);

    Passport::actingAs($user);

    $this->getJson('/api/v1/enumerations/document_categories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.name', 'User documentation');
});

test('enumerations of a different type are not mixed into the list', function () {
    $user = User::factory()->create();
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity, 'name' => 'Design']);
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority, 'name' => 'Normal']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/enumerations/issue_priorities')->assertOk();

    $names = collect($response->json('data'))->pluck('name');
    expect($names->all())->toBe([$priority->name]);
});

test('a project-specific time entry activity override is not included in the global list', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $global = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity, 'name' => 'Development']);
    Enumeration::factory()->create([
        'type' => EnumerationType::TimeEntryActivity,
        'name' => 'Development',
        'project_id' => $project->id,
        'parent_id' => $global->id,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/enumerations/time_entry_activities')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($global->id);
});

test('inactive enumerations are still returned, matching Redmine\'s own unfiltered list', function () {
    $user = User::factory()->create();
    $inactive = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority, 'active' => false]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/enumerations/issue_priorities')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($inactive->id);
});
