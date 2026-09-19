<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;

function indexApiMember(Project $project, string $visibility = 'all', array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility])
    );

    return $user;
}

function indexApiIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

function indexIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

test('the project index hides private issues from a default-visibility role', function () {
    $project = Project::factory()->create();
    $user = indexApiMember($project, 'default');
    $author = User::factory()->create();
    $public = indexApiIssue($project, ['is_private' => false, 'author_id' => $author->id]);
    $private = indexApiIssue($project, ['is_private' => true, 'author_id' => $author->id]);
    $mine = indexApiIssue($project, ['is_private' => true, 'author_id' => $user->id]);
    $assigned = indexApiIssue($project, ['is_private' => true, 'author_id' => $author->id, 'assigned_to_id' => $user->id]);

    Passport::actingAs($user);

    $ids = indexIds($this->getJson("/api/v1/projects/{$project->id}/issues")->assertOk());

    expect($ids)->toContain($public->id, $mine->id, $assigned->id)->not->toContain($private->id);
});

test('the project index shows an own-only role just its own and assigned issues', function () {
    $project = Project::factory()->create();
    $user = indexApiMember($project, 'own');
    $other = User::factory()->create();
    $authored = indexApiIssue($project, ['author_id' => $user->id]);
    $assigned = indexApiIssue($project, ['author_id' => $other->id, 'assigned_to_id' => $user->id]);
    $foreign = indexApiIssue($project, ['author_id' => $other->id]);

    Passport::actingAs($user);

    $ids = indexIds($this->getJson("/api/v1/projects/{$project->id}/issues")->assertOk());

    expect($ids)->toContain($authored->id, $assigned->id)->not->toContain($foreign->id);
});

test('the global index lists visible issues across projects and applies each project\'s tier', function () {
    $open = Project::factory()->create();
    $restricted = Project::factory()->create();
    $hidden = Project::factory()->private()->create();
    $user = indexApiMember($open);
    Member::factory()->for($restricted)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'own'])
    );
    $author = User::factory()->create();
    $inOpen = indexApiIssue($open, ['author_id' => $author->id]);
    $mineRestricted = indexApiIssue($restricted, ['author_id' => $user->id]);
    $foreignRestricted = indexApiIssue($restricted, ['author_id' => $author->id]);
    $inHidden = indexApiIssue($hidden, ['author_id' => $author->id]);

    Passport::actingAs($user);

    $ids = indexIds($this->getJson('/api/v1/issues')->assertOk());

    expect($ids)->toContain($inOpen->id, $mineRestricted->id)
        ->not->toContain($foreignRestricted->id)
        ->not->toContain($inHidden->id);
});

test('the global index needs authentication and returns nothing for a user with no visible project', function () {
    $this->getJson('/api/v1/issues')->assertUnauthorized();

    Passport::actingAs(User::factory()->create());
    indexApiIssue(Project::factory()->private()->create());

    expect(indexIds($this->getJson('/api/v1/issues')->assertOk()))->toBe([]);
});

test('index filters narrow the result and ignore malformed values', function () {
    $project = Project::factory()->create();
    $user = indexApiMember($project);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);
    $tracker = Tracker::factory()->create();
    $openIssue = indexApiIssue($project, ['tracker_id' => $tracker->id, 'assigned_to_id' => $user->id]);
    $closedIssue = indexApiIssue($project, ['status_id' => $closedStatus->id]);
    $unassigned = indexApiIssue($project);

    Passport::actingAs($user);
    $base = "/api/v1/projects/{$project->id}/issues";

    expect(indexIds($this->getJson("{$base}?status_id=closed")))->toBe([$closedIssue->id])
        ->and(indexIds($this->getJson("{$base}?status_id=open")))->not->toContain($closedIssue->id)
        ->and(indexIds($this->getJson("{$base}?status_id={$closedStatus->id}")))->toBe([$closedIssue->id])
        ->and(indexIds($this->getJson("{$base}?tracker_id={$tracker->id}")))->toBe([$openIssue->id])
        ->and(indexIds($this->getJson("{$base}?assigned_to_id=me")))->toBe([$openIssue->id])
        ->and(indexIds($this->getJson("{$base}?assigned_to_id={$user->id}")))->toBe([$openIssue->id])
        ->and(indexIds($this->getJson("{$base}?status_id=*")))->toHaveCount(3)
        ->and(indexIds($this->getJson("{$base}?tracker_id=abc&status_id[]=1")))->toHaveCount(3);

    $this->getJson("{$base}?project_id={$project->id}")->assertOk();
    expect($unassigned->id)->toBeInt();
});

test('project_id narrows the global index and cannot reach a project the caller cannot see', function () {
    $mine = Project::factory()->create();
    $other = Project::factory()->create();
    $secret = Project::factory()->private()->create();
    $user = indexApiMember($mine);
    $inMine = indexApiIssue($mine);
    indexApiIssue($other);
    $inSecret = indexApiIssue($secret);

    Passport::actingAs($user);

    expect(indexIds($this->getJson("/api/v1/issues?project_id={$mine->id}")))->toBe([$inMine->id])
        ->and(indexIds($this->getJson("/api/v1/issues?project_id={$secret->id}")))->not->toContain($inSecret->id);
});

test('sort orders by the requested column and direction and falls back to newest first', function () {
    $project = Project::factory()->create();
    $user = indexApiMember($project);
    $b = indexApiIssue($project, ['subject' => 'Bravo']);
    $a = indexApiIssue($project, ['subject' => 'Alpha']);
    $c = indexApiIssue($project, ['subject' => 'Charlie']);

    Passport::actingAs($user);
    $base = "/api/v1/projects/{$project->id}/issues";

    expect(indexIds($this->getJson("{$base}?sort=subject")))->toBe([$a->id, $b->id, $c->id])
        ->and(indexIds($this->getJson("{$base}?sort=subject:desc")))->toBe([$c->id, $b->id, $a->id])
        ->and(indexIds($this->getJson("{$base}?sort=bogus;drop:desc")))->toBe([$c->id, $a->id, $b->id])
        ->and(indexIds($this->getJson($base)))->toBe([$c->id, $a->id, $b->id]);
});
