<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Format\DateTimes;
use Livewire\Livewire;

/**
 * A15-08: the calendar reads Redmine's calendar?query_id= and offers the
 * same saved-query load/save UI the issue list and Gantt do.
 *
 * @param  array<int, string>  $permissions
 */
function savedQueryCalendarViewer(Project $project, array $permissions = ['view_calendar', 'view_issues', 'save_queries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function savedQueryCalendarIssue(Project $project, Tracker $tracker, string $subject): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
        'start_date' => DateTimes::today()->toDateString(),
    ]);
}

test('a saved issue query can be loaded on the project calendar via query_id and filters its entries', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $project->trackers()->attach([$bug->id, $feature->id]);
    savedQueryCalendarIssue($project, $bug, 'Bug entry');
    savedQueryCalendarIssue($project, $feature, 'Feature entry');
    $viewer = savedQueryCalendarViewer($project);
    $query = SavedQuery::create([
        'name' => 'Bugs only', 'type' => 'issue', 'user_id' => $viewer->id, 'project_id' => $project->id, 'visibility' => 'private',
        'filters' => ['tracker_id' => ['operator' => '=', 'values' => [(string) $bug->id]]], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $project])
        ->assertSee('Bugs only')
        ->assertSee('Feature entry')
        ->call('loadQuery', $query->id)
        ->assertSee('Bug entry')
        ->assertDontSee('Feature entry');

    Livewire::withQueryParams(['query_id' => $query->id])->actingAs($viewer)->test('calendar.index', ['project' => $project])
        ->assertDontSee('Feature entry');
});

test('another user\'s private query cannot be loaded on the calendar', function () {
    $project = Project::factory()->create();
    $viewer = savedQueryCalendarViewer($project);
    $private = SavedQuery::create([
        'name' => 'Someone else', 'type' => 'issue', 'user_id' => User::factory()->create()->id, 'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $project])->call('loadQuery', $private->id)->assertNotFound();
});

test('the calendar filters are saved as an issue query, only with save_queries', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $viewer = savedQueryCalendarViewer($project);

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $project])
        ->set('activeFilterKeys', ['tracker_id'])
        ->set('filterOperators', ['tracker_id' => '='])
        ->set('filterValues', ['tracker_id' => [(string) $tracker->id]])
        ->set('newQueryName', 'From the calendar')
        ->call('saveQuery')
        ->assertHasNoErrors();

    $saved = SavedQuery::query()->where('name', 'From the calendar')->sole();

    expect($saved->type->value ?? $saved->type)->toBe('issue')
        ->and($saved->project_id)->toBe($project->id)
        ->and($saved->filters)->toHaveKey('tracker_id');

    Livewire::actingAs(savedQueryCalendarViewer($project, ['view_calendar', 'view_issues']))->test('calendar.index', ['project' => $project])
        ->set('newQueryName', 'Not allowed')
        ->call('saveQuery')
        ->assertForbidden();
});

test('the cross-project calendar offers global issue queries and honours query_id', function () {
    $project = Project::factory()->create();
    $viewer = savedQueryCalendarViewer($project);
    $query = SavedQuery::create([
        'name' => 'Global public', 'type' => 'issue', 'user_id' => User::factory()->create()->id, 'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('calendar.global-index')->assertSee('Global public');
    Livewire::withQueryParams(['query_id' => $query->id])->actingAs($viewer)->test('calendar.global-index')->assertOk();
});
