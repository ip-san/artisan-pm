<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\ListDefaults;
use Livewire\Livewire;

function listDefaultsViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries']])
    );

    return $user;
}

function listDefaultsIssue(Project $project, array $attributes): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('the defaults keep estimated and spent totals and every time entry column', function () {
    expect(ListDefaults::issueTotals())->toBe(['estimated_hours', 'spent_hours'])
        ->and(ListDefaults::timeEntryColumns())->toBe(['spent_on', 'user_id', 'activity_id', 'issue_id', 'comments', 'hours'])
        ->and(ListDefaults::timeEntriesShowHoursTotal())->toBeTrue();
});

test('unknown keys are dropped and an emptied column list falls back to all columns', function () {
    Setting::set('issue_list_default_totals', ['estimated_remaining_hours', 'bogus']);
    Setting::set('time_entry_list_defaults', ['column_names' => ['hours', 'nope', 'spent_on', 'hours'], 'totalable_names' => []]);

    expect(ListDefaults::issueTotals())->toBe(['estimated_remaining_hours'])
        ->and(ListDefaults::timeEntryColumns())->toBe(['hours', 'spent_on'])
        ->and(ListDefaults::timeEntriesShowHoursTotal())->toBeFalse();

    Setting::set('time_entry_list_defaults', ['column_names' => ['nope']]);
    expect(ListDefaults::timeEntryColumns())->toHaveCount(6);
});

test('the issue list totals follow the chosen sums, including the remaining estimate', function () {
    $project = Project::factory()->create();
    $viewer = listDefaultsViewer($project);
    $one = listDefaultsIssue($project, ['estimated_hours' => 10, 'done_ratio' => 30]);
    listDefaultsIssue($project, ['estimated_hours' => 4, 'done_ratio' => 100]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $one->id, 'hours' => 2.5]);

    Setting::set('issue_list_default_totals', ['estimated_remaining_hours', 'spent_hours']);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');

    expect($list->get('listTotals'))->toMatchArray(['estimated' => 14.0, 'spent' => 2.5, 'remaining' => 7.0]);
    $list->assertSee('実績工数 2.50')->assertSee('残り工数(予定) 7.00')->assertDontSee('予定工数 14.00');
});

test('an empty totals selection hides the totals line and the remaining sum is skipped', function () {
    $project = Project::factory()->create();
    $viewer = listDefaultsViewer($project);
    listDefaultsIssue($project, ['estimated_hours' => 3]);
    Setting::set('issue_list_default_totals', []);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');

    $list->assertDontSee('data-list-totals', false)->assertDontSee('合計:');
    expect($list->get('listTotals')['remaining'])->toBe(0.0);
});

test('the time entry list starts with the configured columns unless the URL says otherwise', function () {
    $project = Project::factory()->create();
    $viewer = listDefaultsViewer($project);
    Setting::set('time_entry_list_defaults', ['column_names' => ['spent_on', 'hours'], 'totalable_names' => ['hours']]);

    expect(Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])->get('columns'))->toBe(['spent_on', 'hours']);
});

test('the hours total on the time entry list can be switched off', function () {
    $project = Project::factory()->create();
    $viewer = listDefaultsViewer($project);
    TimeEntry::factory()->for($project)->create(['hours' => 3]);

    Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])->assertSee('合計:');

    Setting::set('time_entry_list_defaults', ['column_names' => ['spent_on', 'hours'], 'totalable_names' => []]);
    Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])->assertDontSee('合計:');
});

test('the settings form stores the defaults the way Redmine does and validates them', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('issue_list_default_totals', ['spent_hours'])
        ->set('time_entry_list_default_columns', ['spent_on', 'issue_id', 'hours'])
        ->set('time_entry_list_show_total', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('issue_list_default_totals'))->toBe(['spent_hours'])
        ->and(Setting::get('time_entry_list_defaults'))->toBe(['column_names' => ['spent_on', 'issue_id', 'hours'], 'totalable_names' => []]);

    Livewire::actingAs($admin)->test('settings.index')->set('time_entry_list_default_columns', [])->call('save')->assertHasErrors(['time_entry_list_default_columns']);
    Livewire::actingAs($admin)->test('settings.index')->set('issue_list_default_totals', ['bogus'])->call('save')->assertHasErrors(['issue_list_default_totals.0']);
});
