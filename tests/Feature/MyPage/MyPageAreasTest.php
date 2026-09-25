<?php

use App\Enums\DashboardArea;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\UserDashboardBlock;
use Livewire\Livewire;

/**
 * A15-10: My Page's three areas (top/left/right) and the calendar block's
 * week grid.
 */
test('a first-time visitor\'s default blocks land in Redmine\'s default areas', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('my-page.index')->assertOk();

    $byKey = UserDashboardBlock::where('user_id', $user->id)->get()->keyBy('block_key');

    expect($byKey['assigned_issues']->area)->toBe(DashboardArea::Left)
        ->and($byKey['reported_issues']->area)->toBe(DashboardArea::Right)
        ->and($byKey['latest_news']->area)->toBe(DashboardArea::Top);
});

test('a newly added block lands in the top area', function () {
    $user = User::factory()->create();
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'assigned_issues', 'area' => DashboardArea::Left, 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')->call('addBlock', 'watched_issues');

    $added = UserDashboardBlock::where('user_id', $user->id)->where('block_key', 'watched_issues')->sole();
    expect($added->area)->toBe(DashboardArea::Top);
});

test('reordering only affects blocks within the same area', function () {
    $user = User::factory()->create();
    $left1 = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'assigned_issues', 'area' => DashboardArea::Left, 'position' => 0]);
    $left2 = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'watched_issues', 'area' => DashboardArea::Left, 'position' => 1]);
    $right1 = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'reported_issues', 'area' => DashboardArea::Right, 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')->call('reorder', $left2->id, 0);

    $leftOrder = UserDashboardBlock::where('user_id', $user->id)->where('area', DashboardArea::Left)->orderBy('position')->pluck('block_key')->all();

    expect($leftOrder)->toBe(['watched_issues', 'assigned_issues'])
        ->and($right1->fresh()->position)->toBe(0);
});

test('moveToArea moves a block to a different area, appended at the end', function () {
    $user = User::factory()->create();
    $moved = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'assigned_issues', 'area' => DashboardArea::Left, 'position' => 0]);
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'reported_issues', 'area' => DashboardArea::Right, 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')->call('moveToArea', $moved->id, 'right');

    expect($moved->fresh()->area)->toBe(DashboardArea::Right)
        ->and($moved->fresh()->position)->toBe(1);
});

test('the page renders three separate area columns', function () {
    $user = User::factory()->create();
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'assigned_issues', 'area' => DashboardArea::Left, 'position' => 0]);
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'reported_issues', 'area' => DashboardArea::Right, 'position' => 0]);
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'latest_news', 'area' => DashboardArea::Top, 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')
        ->assertSeeHtml('data-my-page-area="top"')
        ->assertSeeHtml('data-my-page-area="left"')
        ->assertSeeHtml('data-my-page-area="right"');
});

test('the calendar block renders a week grid, not a flat list', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Week grid issue',
        'due_date' => now()->toDateString(),
    ]);
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'calendar', 'area' => DashboardArea::Top, 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')
        ->assertSeeHtml('my-page-cal-'.now()->toDateString())
        ->assertSee('#'.$issue->id);
});
