<?php

use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Query;
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

test('the documents block only includes documents from projects the viewer is a member of', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create();

    Document::factory()->for($project)->create(['title' => 'My project doc']);
    Document::factory()->for($otherProject)->create(['title' => 'Other project doc']);

    $component = Livewire::actingAs($user)->test('my-page.index');
    $rows = $component->instance()->blockRows('documents');

    expect($rows->pluck('title')->implode(','))->toContain('My project doc')
        ->not->toContain('Other project doc');
});

test('the activity block shows recent activity only from projects the viewer is a member of', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues']])
    );
    Member::factory()->for($otherProject)->create();

    $defaults = myPageIssueDefaults();
    $visibleIssue = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Visible activity issue']);
    Issue::factory()->for($otherProject)->create([...$defaults, 'subject' => 'Invisible activity issue']);

    $component = Livewire::actingAs($user)->test('my-page.index');
    $rows = $component->instance()->blockRows('activity');

    expect($rows->pluck('title')->implode(','))->toContain((string) $visibleIssue->id)
        ->not->toContain('Invisible activity issue');
});

test('a saved issue query can be added as a block and shows its filtered issues', function () {
    $project = Project::factory()->create();
    $defaults = myPageIssueDefaults();
    $otherStatus = IssueStatus::factory()->create();

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $matching = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'In query']);
    $excluded = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Not in query', 'status_id' => $otherStatus->id]);

    $savedQuery = Query::create([
        'name' => 'My block query',
        'type' => 'issue',
        'user_id' => $user->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => ['status_id' => ['operator' => '=', 'values' => [$defaults['status_id']]]],
        'column_names' => ['subject'],
    ]);

    $component = Livewire::actingAs($user)
        ->test('my-page.index')
        ->call('addBlock', "issue_query:{$savedQuery->id}");

    expect(UserDashboardBlock::where('user_id', $user->id)->where('block_key', "issue_query:{$savedQuery->id}")->exists())->toBeTrue();

    $rows = $component->instance()->blockRows("issue_query:{$savedQuery->id}");

    expect($rows->pluck('title')->join(' '))->toContain("#{$matching->id}")
        ->not->toContain("#{$excluded->id}")
        ->and($component->instance()->blockLabel("issue_query:{$savedQuery->id}"))->toBe('クエリ: My block query');
});

test('another user\'s private query cannot be added as a block', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $savedQuery = Query::create([
        'name' => 'Private query',
        'type' => 'issue',
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => [],
        'column_names' => ['subject'],
    ]);

    Livewire::actingAs($intruder)
        ->test('my-page.index')
        ->call('addBlock', "issue_query:{$savedQuery->id}")
        ->assertStatus(404);
});

test('a block whose saved query was deleted renders empty with a fallback label', function () {
    $user = User::factory()->create();
    UserDashboardBlock::create(['user_id' => $user->id, 'block_key' => 'issue_query:999999', 'position' => 0]);

    $component = Livewire::actingAs($user)->test('my-page.index')->assertOk();

    expect($component->instance()->blockRows('issue_query:999999'))->toBeEmpty()
        ->and($component->instance()->blockLabel('issue_query:999999'))->toBe('クエリ: (削除済み)');
});

test('a query block shows nothing once the owner loses view_issues on its project', function () {
    $project = Project::factory()->private()->create();
    $defaults = myPageIssueDefaults();
    $user = User::factory()->create();
    Issue::factory()->for($project)->create($defaults);

    // The user owns the query but has no membership on the private project.
    $savedQuery = Query::create([
        'name' => 'Orphaned access',
        'type' => 'issue',
        'user_id' => $user->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => [],
        'column_names' => ['subject'],
    ]);

    $component = Livewire::actingAs($user)->test('my-page.index');

    expect($component->instance()->blockRows("issue_query:{$savedQuery->id}"))->toBeEmpty();
});
