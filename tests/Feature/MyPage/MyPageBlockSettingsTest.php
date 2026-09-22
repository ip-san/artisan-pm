<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\UserDashboardBlock;
use App\Support\Dashboard\SavedIssueQueryBlock;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Project, 2: Query}
 */
function blockSettingsQuery(): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    $query = Query::create([
        'name' => 'Block settings query',
        'type' => 'issue',
        'user_id' => $user->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => [],
        'column_names' => ['subject'],
    ]);

    return [$user, $project, $query];
}

test('the same saved query can be placed up to three times', function () {
    [$user, , $query] = blockSettingsQuery();
    $key = "issue_query:{$query->id}";

    $component = Livewire::actingAs($user)->test('my-page.index');
    $component->call('addBlock', $key)->call('addBlock', $key)->call('addBlock', $key)->call('addBlock', $key);

    expect(UserDashboardBlock::where('user_id', $user->id)->where('block_key', $key)->count())->toBe(3)
        ->and($component->instance()->availableSavedQueries->pluck('id')->all())->not->toContain($query->id);
});

test('another block can still be placed only once', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('my-page.index');
    $component->call('addBlock', 'time_entries')->call('addBlock', 'time_entries');

    expect(UserDashboardBlock::where('user_id', $user->id)->where('block_key', 'time_entries')->count())->toBe(1);
});

test('a query block shows the chosen columns and sorts as set', function () {
    [$user, $project, $query] = blockSettingsQuery();
    $defaults = [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
    $low = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Alpha', 'due_date' => '2026-01-05']);
    $high = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Bravo', 'due_date' => '2026-01-01']);
    $key = "issue_query:{$query->id}";

    $component = Livewire::actingAs($user)->test('my-page.index')->call('addBlock', $key);
    $block = UserDashboardBlock::where('user_id', $user->id)->where('block_key', $key)->sole();

    $component->call('openSettings', $block->id)
        ->set('settingsForm', ['columns' => ['project', 'due_date'], 'sort' => 'due_date:asc'])
        ->call('saveSettings')
        ->assertSet('settingsBlockId', null);

    // toEqual: a MySQL JSON column returns its keys in its own order.
    expect($block->fresh()->settings)->toEqual(['columns' => ['project', 'due_date'], 'sort' => 'due_date:asc']);

    $rows = $component->instance()->blockRows($key, $block->fresh()->settings);
    expect($rows->pluck('title')->all()[0])->toContain("#{$high->id}")
        ->and($rows->first()->meta)->toBe("{$project->name} / 2026-01-01")
        ->and($rows->last()->title)->toContain("#{$low->id}");
});

test('a block without settings keeps showing the status', function () {
    [$user, , $query] = blockSettingsQuery();
    $key = "issue_query:{$query->id}";
    $status = IssueStatus::factory()->create(['name' => 'Testing']);
    Issue::factory()->for($query->project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => $status->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $component = Livewire::actingAs($user)->test('my-page.index')->call('addBlock', $key);

    expect($component->instance()->blockRows($key)->first()->meta)->toBe('Testing');
});

test('unknown columns and sorts are dropped from the saved settings', function () {
    expect(SavedIssueQueryBlock::normalizeSettings(['columns' => ['status', 'password', 'project'], 'sort' => 'password:asc']))
        ->toBe(['columns' => ['project', 'status']])
        ->and(SavedIssueQueryBlock::normalizeSettings(['columns' => [], 'sort' => 'id:sideways']))->toBe([])
        ->and(SavedIssueQueryBlock::normalizeSettings(['sort' => 'updated_at:desc']))->toBe(['sort' => 'updated_at:desc']);
});

test('only the owner\'s own block can be configured', function () {
    [$user, , $query] = blockSettingsQuery();
    $stranger = User::factory()->create();
    $block = UserDashboardBlock::create(['user_id' => $stranger->id, 'block_key' => "issue_query:{$query->id}", 'position' => 0]);

    Livewire::actingAs($user)->test('my-page.index')->call('openSettings', $block->id)->assertNotFound();
});

test('the recent time entries block limits itself to the chosen number of days', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'spent_on' => today()->toDateString(), 'comments' => 'recent']);
    TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'spent_on' => today()->subDays(30)->toDateString()]);

    $component = Livewire::actingAs($user)->test('my-page.index')->call('addBlock', 'time_entries');
    $block = UserDashboardBlock::where('user_id', $user->id)->where('block_key', 'time_entries')->sole();

    expect($component->instance()->blockRows('time_entries'))->toHaveCount(2);

    $component->call('openSettings', $block->id)->set('settingsForm', ['days' => '7'])->call('saveSettings');

    expect($block->fresh()->settings)->toBe(['days' => 7])
        ->and($component->instance()->blockRows('time_entries', $block->fresh()->settings))->toHaveCount(1);

    $component->call('openSettings', $block->id)->set('settingsForm', ['days' => ''])->call('saveSettings');
    expect($block->fresh()->settings)->toBeNull();
});
