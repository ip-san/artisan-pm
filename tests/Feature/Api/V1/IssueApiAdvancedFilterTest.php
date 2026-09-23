<?php

use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;

/**
 * A11-17: Redmine's f[]/op[]/v[] and short filters, limit/offset and
 * query_id on GET /issues.json and /projects/{id}/issues.json.
 *
 * @param  array<int, string>  $permissions
 */
function advancedApiMember(Project $project, string $visibility = 'all', array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility])
    );

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function advancedApiIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

/**
 * The ids an API key user gets for $uri.
 *
 * @return array<int, int>
 */
function advancedApiIds(User $user, string $uri): array
{
    $response = test()->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])->getJson($uri)->assertOk();

    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

test('f[]/op[]/v[] filters narrow the list like the web list', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $closed = IssueStatus::factory()->create(['is_closed' => true]);
    $crash = advancedApiIssue($project, ['subject' => 'App crashes on login']);
    $other = advancedApiIssue($project, ['subject' => 'Typo']);
    $done = advancedApiIssue($project, ['subject' => 'Crash fixed', 'status_id' => $closed->id]);
    $key = $user->regenerateApiKey();
    $base = "/api/v1/projects/{$project->id}/issues";

    expect(advancedApiIds($user, "{$base}?f[]=subject&op[subject]=~&v[subject][]=crash"))->toBe([$crash->id, $done->id])
        ->and(advancedApiIds($user, "{$base}?f[]=subject&op[subject]=~&v[subject][]=crash&f[]=status_id&op[status_id]=o"))->toBe([$crash->id])
        ->and(advancedApiIds($user, "{$base}?f[]=status_id&op[status_id]=c"))->toBe([$done->id])
        ->and(advancedApiIds($user, "{$base}?f[]=issue_id&op[issue_id]==&v[issue_id][]={$crash->id},{$other->id}"))->toBe([$crash->id, $other->id])
        ->and(advancedApiIds($user, "{$base}?f[]=status_id&op[status_id]==&v[status_id][]={$closed->id}&v[status_id][]={$other->status_id}"))->toBe([$other->id, $done->id])
        ->and($key)->toBeString();
});

test('the short filter form works for any filter, including updated_on ranges', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $old = advancedApiIssue($project, ['subject' => 'Old one']);
    $old->forceFill(['updated_at' => '2026-01-10 12:00:00', 'created_at' => '2026-01-10 12:00:00'])->saveQuietly();
    $new = advancedApiIssue($project, ['subject' => 'New one']);
    $new->forceFill(['updated_at' => '2026-03-05 09:00:00', 'created_at' => '2026-03-05 09:00:00'])->saveQuietly();
    $base = "/api/v1/projects/{$project->id}/issues";

    expect(advancedApiIds($user, "{$base}?updated_on=".urlencode('><2026-01-01|2026-01-31')))->toBe([$old->id])
        ->and(advancedApiIds($user, "{$base}?updated_on=".urlencode('>=2026-02-01')))->toBe([$new->id])
        ->and(advancedApiIds($user, "{$base}?created_on=2026-01-10"))->toBe([$old->id])
        ->and(advancedApiIds($user, "{$base}?created_on=".urlencode('<=2026-01-10')))->toBe([$old->id])
        ->and(advancedApiIds($user, "{$base}?subject=".urlencode('~new')))->toBe([$new->id])
        ->and(advancedApiIds($user, "{$base}?subject=Old%20one"))->toBe([$old->id])
        ->and(advancedApiIds($user, "{$base}?assigned_to_id=".urlencode('!*')))->toBe([$old->id, $new->id]);
});

test('dotted Redmine filter names reach the renamed filters', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $locked = Version::factory()->for($project)->create(['status' => VersionStatus::Locked]);
    $inLocked = advancedApiIssue($project, ['fixed_version_id' => $locked->id]);
    advancedApiIssue($project);

    expect(advancedApiIds($user, "/api/v1/projects/{$project->id}/issues?f[]=fixed_version.status&op[fixed_version.status]==&v[fixed_version.status][]=locked"))->toBe([$inLocked->id]);
});

test('malformed or unknown filters are ignored instead of failing', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $issues = collect([advancedApiIssue($project), advancedApiIssue($project)])->pluck('id')->sort()->values()->all();
    $base = "/api/v1/projects/{$project->id}/issues";

    expect(advancedApiIds($user, "{$base}?f[]=tracker_id&op[tracker_id]==&v[tracker_id][]=abc"))->toBe($issues)
        ->and(advancedApiIds($user, "{$base}?f[]=bogus&op[bogus]==&v[bogus][]=1"))->toBe($issues)
        ->and(advancedApiIds($user, "{$base}?f[]=subject&op[subject]=%3F%3F&v[subject][]=x"))->toBe($issues)
        ->and(advancedApiIds($user, "{$base}?f[]=created_on&op[created_on]==&v[created_on][]=yesterday"))->toBe($issues)
        ->and(advancedApiIds($user, "{$base}?f[]=issue_id&op[issue_id]=%3E%3D&v[issue_id][]=1;drop"))->toBe($issues)
        ->and(advancedApiIds($user, "{$base}?f=subject&op=x&v=y"))->toBe($issues);
});

test('limit and offset page the list as Redmine does', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $ids = collect(range(1, 27))->map(fn () => advancedApiIssue($project)->id)->sortDesc()->values();
    $key = $user->regenerateApiKey();
    $base = "/api/v1/projects/{$project->id}/issues";

    $default = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson($base)->assertOk();
    $window = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("{$base}?limit=2&offset=3")->assertOk();
    $capped = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("{$base}?limit=500")->assertOk();
    $paged = $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("{$base}?limit=10&page=3")->assertOk();

    expect($default->json('data'))->toHaveCount(25)
        ->and($default->json('total_count'))->toBe(27)
        ->and($default->json('limit'))->toBe(25)
        ->and($default->json('offset'))->toBe(0)
        ->and(collect($window->json('data'))->pluck('id')->all())->toBe($ids->slice(3, 2)->values()->all())
        ->and($window->json('offset'))->toBe(3)
        ->and($capped->json('limit'))->toBe(100)
        ->and($capped->json('data'))->toHaveCount(27)
        ->and($paged->json('offset'))->toBe(20)
        ->and($paged->json('data'))->toHaveCount(7);
});

test('query_id applies a saved query the caller may see', function () {
    $project = Project::factory()->create();
    $user = advancedApiMember($project);
    $owner = User::factory()->create();
    $match = advancedApiIssue($project, ['subject' => 'Needle here']);
    advancedApiIssue($project, ['subject' => 'Haystack']);
    $public = SavedQuery::create(['type' => 'issue', 'name' => 'Needles', 'visibility' => 'public', 'user_id' => $owner->id, 'project_id' => $project->id, 'filters' => ['subject' => ['operator' => '~', 'values' => ['needle']]], 'column_names' => []]);
    $private = SavedQuery::create(['type' => 'issue', 'name' => 'Mine', 'visibility' => 'private', 'user_id' => $owner->id, 'filters' => [], 'column_names' => []]);
    $timeQuery = SavedQuery::create(['type' => 'time_entry', 'name' => 'Time', 'visibility' => 'public', 'user_id' => $owner->id, 'filters' => [], 'column_names' => []]);
    $key = $user->regenerateApiKey();

    expect(advancedApiIds($user, "/api/v1/projects/{$project->id}/issues?query_id={$public->id}"))->toBe([$match->id])
        ->and(advancedApiIds($user, "/api/v1/issues?query_id={$public->id}"))->toBe([$match->id]);

    $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("/api/v1/issues?query_id={$private->id}")->assertForbidden();
    $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("/api/v1/issues?query_id={$timeQuery->id}")->assertNotFound();
    $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson('/api/v1/issues?query_id=999999')->assertNotFound();
});

test('no filter lets an API key user reach an issue they cannot see', function () {
    $project = Project::factory()->private()->create();
    $hiddenProject = Project::factory()->private()->create();
    $user = advancedApiMember($project, 'default', ['view_issues', 'view_issue_watchers', 'view_time_entries']);
    $author = User::factory()->create();
    $visible = advancedApiIssue($project, ['author_id' => $author->id, 'subject' => 'Public needle']);
    $private = advancedApiIssue($project, ['author_id' => $author->id, 'is_private' => true, 'subject' => 'Private needle', 'parent_id' => $visible->id]);
    $hidden = advancedApiIssue($hiddenProject, ['author_id' => $author->id, 'subject' => 'Hidden needle']);
    IssueRelation::create(['issue_from_id' => $hidden->id, 'issue_to_id' => $visible->id, 'relation_type' => 'relates']);
    $private->watchers()->create(['user_id' => $user->id]);

    $forbidden = [$private->id, $hidden->id];
    $uris = [
        "/api/v1/issues?f[]=issue_id&op[issue_id]==&v[issue_id][]={$private->id},{$hidden->id},{$visible->id}",
        '/api/v1/issues?f[]=subject&op[subject]=~&v[subject][]=needle',
        '/api/v1/issues?f[]=any_searchable&op[any_searchable]=~&v[any_searchable][]=needle',
        "/api/v1/issues?f[]=parent_id&op[parent_id]=~&v[parent_id][]={$visible->id}",
        "/api/v1/issues?f[]=project_id&op[project_id]==&v[project_id][]={$hiddenProject->id}",
        "/api/v1/issues?f[]=relates&op[relates]==p&v[relates][]={$hiddenProject->id}",
        '/api/v1/issues?f[]=watcher_id&op[watcher_id]==&v[watcher_id][]=me',
        '/api/v1/issues?f[]=status_id&op[status_id]=*',
        '/api/v1/issues?is_private=1',
        "/api/v1/issues?project_id={$hiddenProject->id}",
        "/api/v1/issues?issue_id={$hidden->id}",
        "/api/v1/projects/{$project->id}/issues?f[]=issue_id&op[issue_id]==&v[issue_id][]={$private->id}",
        "/api/v1/projects/{$project->id}/issues?f[]=subject&op[subject]=~&v[subject][]=needle&limit=100",
    ];

    foreach ($uris as $uri) {
        expect(array_intersect(advancedApiIds($user, $uri), $forbidden))->toBe([], $uri);
    }

    expect(advancedApiIds($user, "/api/v1/issues?f[]=relates&op[relates]=*"))->toBe([$visible->id]);

    $this->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])
        ->getJson("/api/v1/projects/{$hiddenProject->id}/issues?f[]=subject&op[subject]=~&v[subject][]=needle")
        ->assertForbidden();
});
