<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function ganttLimitViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_gantt']])
    );

    return $user;
}

function ganttLimitIssues(Project $project, int $count, string $start = '2026-01-01', string $due = '2026-01-31'): void
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    Issue::factory($count)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => $start,
        'due_date' => $due,
    ]);
}

test('the chart draws at most gantt_items_limit rows and says so', function () {
    Setting::set('gantt_items_limit', 3);
    $project = Project::factory()->create();
    ganttLimitIssues($project, 5);

    $chart = Livewire::actingAs(ganttLimitViewer($project))->test('gantt.index', ['project' => $project]);

    expect($chart->get('rows'))->toHaveCount(3)
        ->and($chart->get('allRows'))->toHaveCount(5)
        ->and($chart->get('rowsTruncated'))->toBeTrue();
    $chart->assertSee('先頭3件だけを表示');
});

test('an items limit of 0 draws every row and the default is 500', function () {
    $project = Project::factory()->create();
    ganttLimitIssues($project, 5);
    $viewer = ganttLimitViewer($project);

    expect(Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->get('rowsTruncated'))->toBeFalse();

    Setting::set('gantt_items_limit', 0);
    expect(Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->get('rows'))->toHaveCount(5);
});

test('the chart spans at most gantt_months_limit months and clips longer bars', function () {
    Setting::set('gantt_months_limit', 3);
    $project = Project::factory()->create();
    ganttLimitIssues($project, 1, '2026-01-01', '2026-12-31');

    $chart = Livewire::actingAs(ganttLimitViewer($project))->test('gantt.index', ['project' => $project]);

    expect($chart->get('rangeEnd')->toDateString())->toBe('2026-03-31')
        ->and($chart->get('monthBands'))->toHaveCount(3)
        ->and($chart->get('monthsTruncated'))->toBeTrue();
    $row = $chart->get('rows')->first();
    expect($chart->instance()->barLeftPercent($row))->toBe(0.0)
        ->and($chart->instance()->barWidthPercent($row))->toBe(100.0);
    $chart->assertSee('開始から3か月分だけを表示');
});

test('a chart shorter than the months limit is untouched', function () {
    $project = Project::factory()->create();
    ganttLimitIssues($project, 1, '2026-01-01', '2026-02-15');

    $chart = Livewire::actingAs(ganttLimitViewer($project))->test('gantt.index', ['project' => $project]);

    expect($chart->get('rangeEnd')->toDateString())->toBe('2026-02-15')
        ->and($chart->get('monthsTruncated'))->toBeFalse();
});

test('the settings form saves the gantt limits', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('gantt_items_limit', 100)->set('gantt_months_limit', 12)->call('save')->assertHasNoErrors();
    expect(Setting::get('gantt_items_limit'))->toBe(100)->and(Setting::get('gantt_months_limit'))->toBe(12);

    Livewire::actingAs($admin)->test('settings.index')->set('gantt_items_limit', -1)->call('save')->assertHasErrors(['gantt_items_limit']);
});
