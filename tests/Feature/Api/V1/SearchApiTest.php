<?php

use App\Enums\IssueVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;

function apiSearchMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function apiSearchIssue(Project $project, array $overrides = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        ...$overrides,
    ]);
}

test('unauthenticated requests are rejected for global search', function () {
    $this->getJson('/api/v1/search?q=test')->assertUnauthorized();
});

test('unauthenticated requests are rejected for project-scoped search', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/search?q=test")->assertUnauthorized();
});

test('the global search finds a matching issue in a project the user can view_issues in', function () {
    $project = Project::factory()->create();
    $user = apiSearchMember($project, ['view_project', 'view_issues']);
    $issue = apiSearchIssue($project, ['subject' => 'Fix login bug']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=login');

    $response->assertOk();
    $result = collect($response->json('data'))->firstWhere('type', 'issue');
    expect($result)->not->toBeNull()
        ->and($result['title'])->toContain('Fix login bug')
        ->and($result['url'])->toContain((string) $issue->id);
});

test('the global search excludes projects the user has no access to at all', function () {
    $visibleProject = Project::factory()->create();
    $user = apiSearchMember($visibleProject, ['view_project', 'view_issues']);
    $hiddenProject = Project::factory()->private()->create();
    apiSearchIssue($hiddenProject, ['subject' => 'Secret hidden issue']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=secret');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('scope=my_projects excludes publicly visible projects the user is not a member of', function () {
    $memberProject = Project::factory()->create();
    $user = apiSearchMember($memberProject, ['view_project', 'view_issues']);
    $publicProject = Project::factory()->create(['is_public' => true]);
    apiSearchIssue($publicProject, ['subject' => 'Public findable issue']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable&scope=my_projects');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('an invalid scope value is rejected', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/search?q=test&scope=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope']);
});

test('a member with view_issues can search within their own project', function () {
    $project = Project::factory()->create();
    $user = apiSearchMember($project, ['view_project', 'view_issues']);
    apiSearchIssue($project, ['subject' => 'Project findable issue']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/search?q=findable");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('type'))->toContain('issue');
});

test('a non-member cannot search a private project', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/search?q=test")->assertForbidden();
});

test('subprojects=true includes a child project\'s results', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $user = apiSearchMember($parent, ['view_project', 'view_issues']);
    Member::factory()->for($child)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    apiSearchIssue($child, ['subject' => 'Child project findable issue']);

    Passport::actingAs($user);

    $withoutSubprojects = $this->getJson("/api/v1/projects/{$parent->id}/search?q=findable");
    expect(collect($withoutSubprojects->json('data')))->toBeEmpty();

    $withSubprojects = $this->getJson("/api/v1/projects/{$parent->id}/search?q=findable&subprojects=1");
    expect(collect($withSubprojects->json('data'))->pluck('type'))->toContain('issue');
});

test('open_issues=1 excludes closed issues', function () {
    $project = Project::factory()->create();
    $user = apiSearchMember($project, ['view_project', 'view_issues']);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);
    apiSearchIssue($project, ['subject' => 'Findable closed issue', 'status_id' => $closedStatus->id]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable&open_issues=1');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('the response shape exposes type/title/url/description/updated_at only', function () {
    $project = Project::factory()->create();
    $user = apiSearchMember($project, ['view_project', 'view_issues']);
    apiSearchIssue($project, ['subject' => 'Issue for shape check']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=shape');

    $result = collect($response->json('data'))->first();
    expect(array_keys($result))->toBe(['type', 'title', 'url', 'description', 'updated_at']);
});

test('an empty query returns no results', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('a member with own-only issues_visibility cannot see another user\'s issue in search results', function () {
    $project = Project::factory()->create();
    $ownRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => IssueVisibility::Own->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($ownRole);
    apiSearchIssue($project, ['subject' => 'Issue findable but owned by someone else']);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('a member with own-only issues_visibility can see their own issue in search results', function () {
    $project = Project::factory()->create();
    $ownRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => IssueVisibility::Own->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($ownRole);
    apiSearchIssue($project, ['subject' => 'Issue I own findable', 'author_id' => $user->id]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable');

    expect(collect($response->json('data'))->pluck('type'))->toContain('issue');
});

test('a private issue does not leak into search results for a member who is not its author or assignee', function () {
    $project = Project::factory()->create();
    // issues_visibility must be Default (not the Role factory's own
    // default of All) — an All-tier role legitimately sees every issue
    // including private ones, matching IssuePolicy::view()'s own
    // unconditional All bypass; only Default restricts is_private.
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => IssueVisibility::Default->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    apiSearchIssue($project, ['subject' => 'Private findable issue', 'is_private' => true]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable');

    expect(collect($response->json('data')))->toBeEmpty();
});

test('a private issue is visible in search results to its author', function () {
    $project = Project::factory()->create();
    // Default (not the Role factory's own default of All), so this
    // actually proves the author exception rather than an All-tier role
    // seeing every issue regardless of authorship.
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => IssueVisibility::Default->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    apiSearchIssue($project, ['subject' => 'Private issue I authored findable', 'is_private' => true, 'author_id' => $user->id]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=findable');

    expect(collect($response->json('data'))->pluck('type'))->toContain('issue');
});
