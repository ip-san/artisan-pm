<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
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
