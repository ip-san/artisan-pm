<?php

use App\Enums\RoleBuiltin;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/roles')->assertUnauthorized();
});

test('any authenticated user can list givable roles', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'Manager', 'permissions' => ['view_issues', 'edit_issues']]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/roles');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $role->id)
        ->assertJsonPath('data.0.name', 'Manager')
        ->assertJsonPath('data.0.permissions', ['view_issues', 'edit_issues']);
});

test('the builtin anonymous and non-member roles are excluded from the list', function () {
    $user = User::factory()->create();
    Role::factory()->create(['name' => 'Anonymous', 'builtin' => RoleBuiltin::Anonymous]);
    Role::factory()->create(['name' => 'Non member', 'builtin' => RoleBuiltin::NonMember]);
    $normal = Role::factory()->create(['name' => 'Developer']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/roles');

    $names = collect($response->json('data'))->pluck('name');
    expect($names->all())->toBe([$normal->name]);
});

test('any authenticated user can show a single role', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'Developer', 'assignable' => true]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $role->id)
        ->assertJsonPath('data.name', 'Developer')
        ->assertJsonPath('data.assignable', true);
});

test('a builtin role can still be shown directly by id', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'Anonymous', 'builtin' => RoleBuiltin::Anonymous]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $role->id);
});

test('a member of no projects can still list roles, matching the unscoped index', function () {
    $user = User::factory()->create();
    Role::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/roles')->assertOk();
});
