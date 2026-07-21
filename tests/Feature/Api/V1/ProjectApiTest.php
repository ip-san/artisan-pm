<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

test('a user only sees projects they can view', function () {
    $user = User::factory()->create();
    $visible = Project::factory()->create(['name' => 'Visible']);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden']);

    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($visible)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/projects');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

test('viewing a single project the user cannot see is forbidden', function () {
    $user = User::factory()->create();
    $private = Project::factory()->private()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$private->id}")->assertForbidden();
});

test('a public project is visible to any authenticated user', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.identifier', $project->identifier);
});
