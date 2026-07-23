<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Laravel\Passport\Passport;

function apiVersionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/versions")->assertUnauthorized();
});

test('a member with view_files can list a project\'s versions', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files']);
    $version = Version::factory()->for($project)->create(['name' => 'v1.0']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/versions");

    $response->assertOk()->assertJsonPath('data.0.id', $version->id);
});

test('a non-member cannot list versions in a private project', function () {
    $project = Project::factory()->private()->create();
    Version::factory()->for($project)->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/versions")->assertForbidden();
});

test('a member with view_files can show a single version', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files']);
    $version = Version::factory()->for($project)->create(['name' => 'v1.0']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/versions/{$version->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.name', 'v1.0');
});

test('creating a version via the api requires manage_versions permission', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/versions", [
        'name' => 'Should be forbidden',
    ])->assertForbidden();
});

test('a member with manage_versions can create a version via the api', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files', 'manage_versions']);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/versions", [
        'name' => 'Created via API',
        'due_date' => '2026-12-31',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Created via API')
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.status', 'open');

    expect(Version::where('name', 'Created via API')->exists())->toBeTrue();
});

test('a version name must be unique within the project', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files', 'manage_versions']);
    Version::factory()->for($project)->create(['name' => 'Duplicate']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/versions", [
        'name' => 'Duplicate',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

test('updating a version via the api requires manage_versions permission', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files']);
    $version = Version::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/versions/{$version->id}", [
        'status' => 'closed',
    ])->assertForbidden();
});

test('a member with manage_versions can update a version via the api', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files', 'manage_versions']);
    $version = Version::factory()->for($project)->create(['status' => 'open']);

    Passport::actingAs($user);

    $this->putJson("/api/v1/versions/{$version->id}", [
        'status' => 'closed',
    ])->assertOk()->assertJsonPath('data.status', 'closed');

    expect($version->fresh()->status->value)->toBe('closed');
});

test('a member with manage_versions can delete a version via the api', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files', 'manage_versions']);
    $version = Version::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/versions/{$version->id}")->assertNoContent();

    expect(Version::find($version->id))->toBeNull();
});

test('deleting a version via the api requires manage_versions permission', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files']);
    $version = Version::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/versions/{$version->id}")->assertForbidden();

    expect(Version::find($version->id))->not->toBeNull();
});

test('a non-admin cannot set system sharing via the api', function () {
    $project = Project::factory()->create();
    $user = apiVersionMember($project, ['view_files', 'manage_versions']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/versions", [
        'name' => 'System shared attempt',
        'sharing' => 'system',
    ])->assertUnprocessable()->assertJsonValidationErrors(['sharing']);
});
