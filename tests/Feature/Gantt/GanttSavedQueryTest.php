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
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function savedQueryGanttViewer(Project $project, array $permissions = ['view_gantt', 'view_issues', 'save_queries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function savedQueryGanttIssue(Project $project, Tracker $tracker, string $subject): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
        'start_date' => '2026-01-05',
        'due_date' => '2026-01-25',
    ]);
}

test('a saved issue query can be loaded on the gantt and filters its rows', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $project->trackers()->attach([$bug->id, $feature->id]);
    savedQueryGanttIssue($project, $bug, 'Bug row');
    savedQueryGanttIssue($project, $feature, 'Feature row');
    $viewer = savedQueryGanttViewer($project);
    $query = SavedQuery::create([
        'name' => 'Bugs only', 'type' => 'issue', 'user_id' => $viewer->id, 'project_id' => $project->id, 'visibility' => 'private',
        'filters' => ['tracker_id' => ['operator' => '=', 'values' => [(string) $bug->id]]], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->assertSee('Bugs only')
        ->assertSee('Feature row')
        ->call('loadQuery', $query->id)
        ->assertSee('Bug row')
        ->assertDontSee('Feature row');

    Livewire::withQueryParams(['query_id' => $query->id])->actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->assertDontSee('Feature row');
});

test('another user\'s private query or another project\'s query cannot be loaded', function () {
    $project = Project::factory()->create();
    $viewer = savedQueryGanttViewer($project);
    $private = SavedQuery::create([
        'name' => 'Someone else', 'type' => 'issue', 'user_id' => User::factory()->create()->id, 'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'],
    ]);
    $elsewhere = SavedQuery::create([
        'name' => 'Other project', 'type' => 'issue', 'user_id' => $viewer->id, 'project_id' => Project::factory()->create()->id, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->call('loadQuery', $private->id)->assertNotFound();
    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->call('loadQuery', $elsewhere->id)->assertNotFound();
});

test('the gantt filters are saved as an issue query, only with save_queries', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $viewer = savedQueryGanttViewer($project);

    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->set('activeFilterKeys', ['tracker_id'])
        ->set('filterOperators', ['tracker_id' => '='])
        ->set('filterValues', ['tracker_id' => [(string) $tracker->id]])
        ->set('newQueryName', 'From the gantt')
        ->call('saveQuery')
        ->assertHasNoErrors();

    $saved = SavedQuery::query()->where('name', 'From the gantt')->sole();

    expect($saved->type->value ?? $saved->type)->toBe('issue')
        ->and($saved->project_id)->toBe($project->id)
        ->and($saved->filters)->toHaveKey('tracker_id');

    Livewire::actingAs(savedQueryGanttViewer($project, ['view_gantt', 'view_issues']))->test('gantt.index', ['project' => $project])
        ->set('newQueryName', 'Not allowed')
        ->call('saveQuery')
        ->assertForbidden();
});

test('the cross-project gantt offers global issue queries', function () {
    $project = Project::factory()->create();
    $viewer = savedQueryGanttViewer($project);
    SavedQuery::create([
        'name' => 'Global public', 'type' => 'issue', 'user_id' => User::factory()->create()->id, 'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.global-index')->assertSee('Global public');
});
