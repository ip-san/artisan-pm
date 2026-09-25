<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * A15-11: Redmine's Helpers::Gantt month_from/year_from/months, plus the
 * draw_relations/draw_progress_line toggles.
 */
function ganttPeriodViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_gantt']])
    );

    return $user;
}

test('an explicit period overrides the default issue-fitted range', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-06-01', 'due_date' => '2026-06-10',
    ]);

    $page = Livewire::actingAs(ganttPeriodViewer($project))->test('gantt.index', ['project' => $project])
        ->set('yearFrom', 2027)->set('monthFrom', 3)->set('months', 2)
        ->call('applyFilters');

    $chart = $page->get('chart');

    expect($chart->rangeStart->toDateString())->toBe('2027-03-01')
        ->and($chart->rangeEnd->toDateString())->toBe('2027-04-30');
});

test('a chart with no dated issues still renders when an explicit period is given', function () {
    $project = Project::factory()->create();

    $page = Livewire::actingAs(ganttPeriodViewer($project))->test('gantt.index', ['project' => $project])
        ->set('yearFrom', 2027)->set('monthFrom', 1)->set('months', 1)
        ->call('applyFilters');

    expect($page->get('chart')->isEmpty())->toBeFalse();
});

test('an out-of-range months value falls back to 6, matching Redmine', function () {
    $project = Project::factory()->create();

    $page = Livewire::actingAs(ganttPeriodViewer($project))->test('gantt.index', ['project' => $project])
        ->set('yearFrom', 2027)->set('monthFrom', 1)->set('months', 999)
        ->call('applyFilters');

    $chart = $page->get('chart');

    expect($chart->rangeStart->toDateString())->toBe('2027-01-01')
        ->and($chart->rangeEnd->toDateString())->toBe('2027-06-30');
});

test('relation lines are hidden when drawRelations is off', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $a = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id, 'start_date' => '2026-01-01', 'due_date' => '2026-01-05']);
    $b = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $a->status_id, 'priority_id' => $a->priority_id, 'start_date' => '2026-01-06', 'due_date' => '2026-01-10']);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);

    $viewer = ganttPeriodViewer($project);

    $withRelations = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project]);
    expect($withRelations->get('relationLines'))->not->toBe([]);

    $withoutRelations = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->set('drawRelations', false);
    expect($withoutRelations->get('relationLines'))->toBe([]);
});

test('the progress fill is hidden by default and shown once drawProgress is on', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-01-01', 'due_date' => '2026-01-10', 'done_ratio' => 40,
    ]);
    $viewer = ganttPeriodViewer($project);

    $progressBarClass = 'relative h-full rounded bg-brand-bold';

    $withoutProgress = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project]);
    $withoutProgress->assertSeeHtml('data-gantt-draw-progress');
    expect($withoutProgress->html())->not->toContain($progressBarClass);

    $withProgress = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->set('drawProgress', true);
    expect($withProgress->html())->toContain($progressBarClass);
});

test('the cross-project chart honours the same period and toggle params', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-06-01', 'due_date' => '2026-06-10',
    ]);

    $page = Livewire::actingAs(ganttPeriodViewer($project))->test('gantt.global-index')
        ->set('yearFrom', 2027)->set('monthFrom', 3)->set('months', 2)
        ->call('applyFilters');

    $chart = $page->get('chart');

    expect($chart->rangeStart->toDateString())->toBe('2027-03-01')
        ->and($chart->rangeEnd->toDateString())->toBe('2027-04-30');
});
