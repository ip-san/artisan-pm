<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\UserDashboardBlock;
use Livewire\Livewire;

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int}
 */
function myPageIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
}

test('a first-time visitor gets a default set of blocks seeded', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('my-page.index')->assertOk();

    expect(UserDashboardBlock::where('user_id', $user->id)->orderBy('position')->pluck('block_key')->all())
        ->toBe(['assigned_issues', 'reported_issues', 'latest_news']);
});

test('an existing block layout is not reseeded on a later visit', function () {
    $user = User::factory()->create();
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'time_entries', 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index');

    expect(UserDashboardBlock::where('user_id', $user->id)->pluck('block_key')->all())->toBe(['time_entries']);
});

test('adding and removing a block updates the active list', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('my-page.index');
    $component->call('addBlock', 'watched_issues');

    expect($component->get('activeBlocks')->pluck('block_key'))->toContain('watched_issues');

    $blockId = UserDashboardBlock::where('user_id', $user->id)->where('block_key', 'watched_issues')->value('id');
    $component->call('removeBlock', $blockId);

    expect($component->get('activeBlocks')->pluck('block_key'))->not->toContain('watched_issues');
});

test('reordering moves a block to the reported position', function () {
    $user = User::factory()->create();
    $a = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'assigned_issues', 'position' => 0]);
    $b = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'reported_issues', 'position' => 1]);
    $c = UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'watched_issues', 'position' => 2]);

    Livewire::actingAs($user)->test('my-page.index')->call('reorder', $c->id, 0);

    $order = UserDashboardBlock::where('user_id', $user->id)->orderBy('position')->pluck('block_key')->all();
    expect($order)->toBe(['watched_issues', 'assigned_issues', 'reported_issues']);
});

test('the assigned issues block only shows open issues assigned to the viewer', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );
    $defaults = myPageIssueDefaults();
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);

    $open = Issue::factory()->for($project)->create([...$defaults, 'assigned_to_id' => $user->id, 'author_id' => $user->id, 'subject' => 'Open assigned']);
    Issue::factory()->for($project)->create([...$defaults, 'status_id' => $closedStatus->id, 'assigned_to_id' => $user->id, 'author_id' => $user->id, 'subject' => 'Closed assigned']);
    Issue::factory()->for($project)->create([...$defaults, 'author_id' => $user->id, 'subject' => 'Not assigned to me']);

    $component = Livewire::actingAs($user)->test('my-page.index');
    $rows = $component->instance()->blockRows('assigned_issues');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->title)->toContain((string) $open->id);
});

test('the latest news block only includes news from projects the viewer is a member of', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create();

    $ownNews = News::factory()->for($project)->create(['title' => 'My project news']);
    News::factory()->for($otherProject)->create(['title' => 'Other project news']);

    $component = Livewire::actingAs($user)->test('my-page.index');
    $rows = $component->instance()->blockRows('latest_news');

    expect($rows->pluck('title')->implode(','))->toContain('My project news')
        ->not->toContain('Other project news');
});
