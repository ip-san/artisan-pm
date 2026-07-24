<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

function apiQueryMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function createSavedQuery(array $overrides = []): SavedQuery
{
    return SavedQuery::create([
        'type' => 'issue',
        'visibility' => 'public',
        'filters' => [],
        'column_names' => ['subject'],
        ...$overrides,
    ]);
}

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/queries')->assertUnauthorized();
});

test('an authenticated user sees a public global query by default (issue type)', function () {
    $user = User::factory()->create();
    $query = createSavedQuery(['name' => 'Public global query', 'user_id' => $user->id]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/queries');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $query->id)
        ->assertJsonPath('data.0.name', 'Public global query')
        ->assertJsonPath('data.0.is_public', true)
        ->assertJsonPath('data.0.project_id', null);
    expect($response->json('data.0'))->not->toHaveKey('type')->not->toHaveKey('filters');
});

test('another user\'s private global query is not listed', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    createSavedQuery(['name' => 'Private global query', 'user_id' => $owner->id, 'visibility' => 'private']);

    Passport::actingAs($viewer);

    $response = $this->getJson('/api/v1/queries');

    expect(collect($response->json('data'))->pluck('name'))->not->toContain('Private global query');
});

test('a member with view_issues sees project-scoped and global public queries via project_id', function () {
    $project = Project::factory()->create();
    $user = apiQueryMember($project, ['view_issues']);
    $projectQuery = createSavedQuery(['name' => 'Project query', 'user_id' => $user->id, 'project_id' => $project->id]);
    $globalQuery = createSavedQuery(['name' => 'Global query', 'user_id' => $user->id]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/queries?project_id={$project->id}&type=issue");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($projectQuery->id, $globalQuery->id);
});

test('a member without view_issues cannot list project-scoped issue queries', function () {
    $project = Project::factory()->create();
    $user = apiQueryMember($project, ['view_news']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/queries?project_id={$project->id}&type=issue")->assertForbidden();
});

test('type=time_entry is gated on view_time_entries and only lists time_entry queries', function () {
    $project = Project::factory()->create();
    $user = apiQueryMember($project, ['view_time_entries']);
    createSavedQuery(['name' => 'Time entry query', 'type' => 'time_entry', 'user_id' => $user->id, 'project_id' => $project->id, 'column_names' => ['hours']]);
    createSavedQuery(['name' => 'Issue query', 'user_id' => $user->id, 'project_id' => $project->id]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/queries?project_id={$project->id}&type=time_entry");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Time entry query']);
});

test('a member without view_time_entries cannot list project-scoped time_entry queries', function () {
    $project = Project::factory()->create();
    $user = apiQueryMember($project, ['view_news']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/queries?project_id={$project->id}&type=time_entry")->assertForbidden();
});

test('a roles-scoped query is only visible to a member holding a matching role', function () {
    $project = Project::factory()->create();
    $matchingRole = Role::factory()->create();
    $otherRole = Role::factory()->create();
    $matchingUser = apiQueryMember($project, ['view_issues']);
    $otherUser = apiQueryMember($project, ['view_issues']);
    Member::query()->where('user_id', $matchingUser->id)->first()->roles()->attach($matchingRole);
    Member::query()->where('user_id', $otherUser->id)->first()->roles()->attach($otherRole);

    $query = createSavedQuery([
        'name' => 'Roles-scoped query', 'user_id' => User::factory()->create()->id,
        'project_id' => $project->id, 'visibility' => 'roles',
    ]);
    $query->roles()->attach($matchingRole);

    Passport::actingAs($matchingUser);
    $matchingResponse = $this->getJson("/api/v1/queries?project_id={$project->id}&type=issue");
    expect(collect($matchingResponse->json('data'))->pluck('id'))->toContain($query->id);

    Passport::actingAs($otherUser);
    $otherResponse = $this->getJson("/api/v1/queries?project_id={$project->id}&type=issue");
    expect(collect($otherResponse->json('data'))->pluck('id'))->not->toContain($query->id);
});

test('a public global query is visible even to a user with no project memberships at all, matching Redmine\'s own project_id IS NULL bypass', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $query = createSavedQuery(['name' => 'Cross-project visible query', 'user_id' => $owner->id]);

    Passport::actingAs($outsider);

    $response = $this->getJson('/api/v1/queries');

    expect(collect($response->json('data'))->pluck('id'))->toContain($query->id);
});

test('a nonexistent project_id is rejected by validation', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/queries?project_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id']);
});

test('an invalid type value is rejected', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/queries?type=bogus')->assertUnprocessable()->assertJsonValidationErrors(['type']);
});
