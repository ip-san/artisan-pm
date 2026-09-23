<?php

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return array{total: int, roles: int}
 */
function countQueriesWhile(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [
        'total' => count($log),
        'roles' => collect($log)->filter(fn (array $entry) => str_contains($entry['query'], 'from "roles"'))->count(),
    ];
}

/**
 * @return array{user: User, project: Project, tracker: Tracker, activity: Enumeration}
 */
function visibilityCacheFixture(int $otherProjects = 3): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'view_time_entries'], 'issues_visibility' => 'default'])
    );
    Project::factory()->count($otherProjects)->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    return ['user' => $user, 'project' => $project, 'tracker' => $tracker, 'activity' => $activity];
}

/**
 * @param  array{user: User, project: Project, tracker: Tracker, activity: Enumeration}  $fixture
 */
function addVersionsWithSpentTime(array $fixture, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        $version = Version::factory()->for($fixture['project'])->create();
        $issue = Issue::factory()->for($fixture['project'])->create(['fixed_version_id' => $version->id, 'tracker_id' => $fixture['tracker']->id]);
        TimeEntry::factory()->for($fixture['project'])->for($fixture['user'])->create(['issue_id' => $issue->id, 'activity_id' => $fixture['activity']->id, 'hours' => 1]);
    }
}

test('the roadmap resolves issue visibility once, however many versions it shows', function () {
    $fixture = visibilityCacheFixture();
    $render = fn () => Livewire::actingAs($fixture['user'])->test('versions.roadmap', ['project' => $fixture['project']])->assertOk();

    addVersionsWithSpentTime($fixture, 2);
    $withTwo = countQueriesWhile($render);
    addVersionsWithSpentTime($fixture, 4);
    $withSix = countQueriesWhile($render);

    // Before memoization: 121 queries / 43 on roles with 2 versions, 352 / 127 with 6; after: 20 / 2 and 35 / 2.
    expect($withSix['roles'])->toBe($withTwo['roles'])
        ->and($withTwo['roles'])->toBeLessThanOrEqual(3)
        ->and($withSix['total'] - $withTwo['total'])->toBeLessThanOrEqual(4 * 7);
});

test('the time report resolves issue visibility once for every issue axis', function () {
    $fixture = visibilityCacheFixture();
    addVersionsWithSpentTime($fixture, 6);

    $report = countQueriesWhile(fn () => Livewire::actingAs($fixture['user'])
        ->test('time-entries.report', ['project' => $fixture['project']])
        ->set('criteria', ['issue', 'tracker'])
        ->assertOk());

    // Before memoization: 37 queries / 11 on roles; after: 19 / 2.
    expect($report['roles'])->toBeLessThanOrEqual(3)
        ->and($report['total'])->toBeLessThanOrEqual(20);
});

test('memoized visibility follows membership, role, group, project and module changes in the same request', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create(['is_private' => true]);
    $user = User::factory()->create();
    $visibleIds = fn () => Issue::query()->visible($user)->pluck('id')->all();

    expect($visibleIds())->toBe([]);

    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'all']);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);
    expect($visibleIds())->toBe([$issue->id])
        ->and($issue->isVisibleTo($user))->toBeTrue();

    $role->update(['issues_visibility' => 'default']);
    expect($visibleIds())->toBe([])
        ->and($issue->isVisibleTo($user))->toBeFalse();

    $role->update(['issues_visibility' => 'all']);
    $project->syncModules([]);
    expect($visibleIds())->toBe([]);

    $project->syncModules(ProjectModuleKey::defaults());
    expect($visibleIds())->toBe([$issue->id]);

    $member->roles()->detach($role);
    expect($visibleIds())->toBe([]);

    $group = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($role);
    expect($visibleIds())->toBe([]);

    $user->groups()->attach($group);
    expect($visibleIds())->toBe([$issue->id]);

    $user->groups()->detach($group);
    $project->update(['is_public' => false]);
    expect($visibleIds())->toBe([])
        ->and(app(AuthorizationService::class)->issueVisibilityRules($user, $project->fresh()))->toBe([]);
});

test('an unsaved change to the project\'s publicity is not answered from the memo', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_issues']]);
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $authorization = app(AuthorizationService::class);

    expect($authorization->issueVisibilityRules($user, $project))->toBe(['all' => null]);

    $project->is_public = false;
    expect($authorization->issueVisibilityRules($user, $project))->toBe([]);
});

test('the memo survives read-only renders and is shared across Livewire renders until a write', function () {
    $fixture = visibilityCacheFixture(0);
    addVersionsWithSpentTime($fixture, 1);
    Livewire::actingAs($fixture['user'])->test('versions.roadmap', ['project' => $fixture['project']])->assertOk();

    $second = countQueriesWhile(fn () => Livewire::actingAs($fixture['user'])->test('versions.roadmap', ['project' => $fixture['project']])->assertOk());
    expect($second['roles'])->toBeLessThanOrEqual(1);

    Member::query()->where('user_id', $fixture['user']->id)->first()->roles()->detach();
    Livewire::actingAs($fixture['user'])->test('versions.roadmap', ['project' => $fixture['project']])->assertForbidden();
});
