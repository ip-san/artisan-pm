<?php

use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

function apiIssueCategoryMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/issue_categories")->assertUnauthorized();
});

test('a member with manage_categories can list a project\'s issue categories', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/issue_categories");

    $response->assertOk()->assertJsonPath('data.0.id', $category->id);
});

test('a member without manage_categories cannot list issue categories', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['view_issues']);
    IssueCategory::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/issue_categories")->assertForbidden();
});

test('a member with manage_categories can show a single issue category', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/issue_categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonPath('data.name', 'Backend');
});

test('creating an issue category via the api requires manage_categories permission', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['view_issues']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/issue_categories", [
        'name' => 'Should be forbidden',
    ])->assertForbidden();
});

test('a member with manage_categories can create an issue category via the api', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/issue_categories", [
        'name' => 'Created via API',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Created via API')
        ->assertJsonPath('data.project_id', $project->id);

    expect(IssueCategory::where('name', 'Created via API')->exists())->toBeTrue();
});

test('an issue category name must be unique within the project', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    IssueCategory::factory()->for($project)->create(['name' => 'Duplicate']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/issue_categories", [
        'name' => 'Duplicate',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

test('assigned_to_id must reference a member of the project', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    $outsider = User::factory()->create();

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/issue_categories", [
        'name' => 'With bad assignee',
        'assigned_to_id' => $outsider->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to_id']);
});

test('updating an issue category via the api requires manage_categories permission', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['view_issues']);
    $category = IssueCategory::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/issue_categories/{$category->id}", [
        'name' => 'Renamed',
    ])->assertForbidden();
});

test('a member with manage_categories can update an issue category via the api', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Old name']);

    Passport::actingAs($user);

    $this->putJson("/api/v1/issue_categories/{$category->id}", [
        'name' => 'New name',
    ])->assertOk()->assertJsonPath('data.name', 'New name');

    expect($category->fresh()->name)->toBe('New name');
});

test('a member with manage_categories can delete an issue category via the api', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['manage_categories']);
    $category = IssueCategory::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issue_categories/{$category->id}")->assertNoContent();

    expect(IssueCategory::find($category->id))->toBeNull();
});

test('deleting an issue category via the api requires manage_categories permission', function () {
    $project = Project::factory()->create();
    $user = apiIssueCategoryMember($project, ['view_issues']);
    $category = IssueCategory::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issue_categories/{$category->id}")->assertForbidden();

    expect(IssueCategory::find($category->id))->not->toBeNull();
});
