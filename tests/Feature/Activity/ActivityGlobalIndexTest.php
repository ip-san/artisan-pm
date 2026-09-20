<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function activityGlobalMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the global activity feed aggregates entries across every visible project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = activityGlobalMember($projectA, ['view_project', 'view_issues']);
    Member::factory()->for($projectB)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_news']]));

    Issue::factory()->for($projectA)->create(['created_at' => now()->subDay()]);
    News::factory()->for($projectB)->create(['created_at' => now()->subDay()]);

    $component = Livewire::actingAs($user)->test('activity.global-index');
    $types = $component->get('entries')->pluck('type');

    expect($types)->toContain('issue')->toContain('news');
});

test('the global activity feed excludes entries from a project the viewer cannot see', function () {
    $visible = Project::factory()->create();
    $hidden = Project::factory()->private()->create();
    $user = activityGlobalMember($visible, ['view_project', 'view_issues']);

    Issue::factory()->for($visible)->create(['created_at' => now()->subDay()]);
    Issue::factory()->for($hidden)->create(['created_at' => now()->subDay()]);

    $component = Livewire::actingAs($user)->test('activity.global-index');

    expect($component->get('entries'))->toHaveCount(1);
});

test('the global activity feed still respects each project\'s own permission checks', function () {
    $withIssues = Project::factory()->create();
    $withoutIssues = Project::factory()->create();
    $user = activityGlobalMember($withIssues, ['view_project', 'view_issues']);
    Member::factory()->for($withoutIssues)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));

    Issue::factory()->for($withIssues)->create(['created_at' => now()->subDay()]);
    Issue::factory()->for($withoutIssues)->create(['created_at' => now()->subDay()]);

    $component = Livewire::actingAs($user)->test('activity.global-index');

    expect($component->get('entries'))->toHaveCount(1);
});

test('an entry outside the date range is excluded from the global feed', function () {
    $project = Project::factory()->create();
    $user = activityGlobalMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['created_at' => now()->subDays(30)]);

    $component = Livewire::actingAs($user)
        ->test('activity.global-index')
        ->set('from', now()->subDays(7)->toDateString())
        ->set('to', now()->toDateString())
        ->call('applyFilters');

    expect($component->get('entries'))->toHaveCount(0);
});

test('activity_days_default widens the default date range shown on mount', function () {
    Setting::set('activity_days_default', 30);
    $project = Project::factory()->create();
    $user = activityGlobalMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['created_at' => now()->subDays(20)]);

    $component = Livewire::actingAs($user)->test('activity.global-index');

    expect($component->get('entries'))->toHaveCount(1);
});

test('an issue entry carries its author\'s raw user id, not just the display name', function () {
    // ActivityEntry::$authorId is what the public profile page's "recent
    // activity" section filters on — a regression here would silently
    // break that filter rather than fail loudly, since authorName would
    // still render correctly either way.
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $user = activityGlobalMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['created_at' => now()->subDay(), 'author_id' => $author->id]);

    $entry = Livewire::actingAs($user)->test('activity.global-index')->get('entries')->firstOrFail();

    expect($entry->authorId)->toBe($author->id);
});

test('each provider reads all the projects in one query', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_news']]);

    foreach (Project::factory(6)->create() as $project) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
        Issue::factory()->for($project)->create(['created_at' => now()->subDay()]);
        News::factory()->for($project)->create(['created_at' => now()->subDay()]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $entries = Livewire::actingAs($user)->test('activity.global-index')->get('entries');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $scans = fn (string $table) => $queries->filter(fn (string $sql) => str_starts_with($sql, "select * from \"{$table}\" where \"project_id\" in"))->count();

    expect($entries)->toHaveCount(12)
        ->and($scans('issues'))->toBe(1)
        ->and($scans('news'))->toBe(1);
});

test('the entries of several projects keep each their own project link', function () {
    $first = Project::factory()->create();
    $second = Project::factory()->create();
    $user = activityGlobalMember($first, ['view_project', 'view_issues']);
    Member::factory()->for($second)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    $inFirst = Issue::factory()->for($first)->create(['created_at' => now()->subDay()]);
    $inSecond = Issue::factory()->for($second)->create(['created_at' => now()->subDay()]);

    $urls = Livewire::actingAs($user)->test('activity.global-index')->get('entries')->pluck('url');

    expect($urls)->toContain(route('issues.show', [$first, $inFirst]))
        ->toContain(route('issues.show', [$second, $inSecond]));
});

test('the period buttons move the window by its own length', function () {
    $project = Project::factory()->create();
    $user = activityGlobalMember($project, ['view_project', 'view_issues']);

    $component = Livewire::actingAs($user)->test('activity.global-index')
        ->set('from', '2026-09-10')->set('to', '2026-09-16')
        ->call('previousPeriod')
        ->assertSet('from', '2026-09-03')->assertSet('to', '2026-09-09')
        ->call('nextPeriod')->call('nextPeriod')
        ->assertSet('from', '2026-09-17')->assertSet('to', '2026-09-23');
});
