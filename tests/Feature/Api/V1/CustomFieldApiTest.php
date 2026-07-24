<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/custom_fields')->assertUnauthorized();
});

test('a non-admin cannot list custom fields', function () {
    $user = User::factory()->create();
    CustomField::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/custom_fields')->assertForbidden();
});

test('an admin can list custom fields', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Client email', 'customized_type' => CustomizableType::Issue]);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/custom_fields');

    $response->assertOk()->assertJsonPath('data.0.id', $field->id)
        ->assertJsonPath('data.0.name', 'Client email')
        ->assertJsonPath('data.0.customized_type', 'issue');
});

test('a list-format custom field exposes its possible values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->list(['Low', 'High'])->create();

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/custom_fields');

    expect($response->json('data.0.possible_values'))->toBe([
        ['value' => 'Low', 'label' => 'Low'],
        ['value' => 'High', 'label' => 'High'],
    ]);
});

test('a custom field with no possible values returns an empty array', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->create();

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/custom_fields');

    expect($response->json('data.0.possible_values'))->toBe([]);
});

test('tracker and role restrictions are exposed as id arrays', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $field->trackers()->attach($tracker);
    $field->roles()->attach($role);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/custom_fields');

    $response->assertJsonPath('data.0.tracker_ids', [$tracker->id])
        ->assertJsonPath('data.0.role_ids', [$role->id]);
});
