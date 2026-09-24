<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttRow;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function zoomGanttViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_gantt', 'view_issues']]));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function zoomGanttIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-01-05',
        'due_date' => '2026-01-25',
        ...$attributes,
    ]);
}

function zoomGanttRow(string $start, string $due, int $doneRatio): GanttRow
{
    return new GanttRow(1, null, 'Row', Carbon::parse($start), Carbon::parse($due), $doneRatio, 'Bug', 'New', false, 0);
}

test('the headers follow the zoom: months, then weeks, then days, then weekdays', function () {
    $project = Project::factory()->create();
    zoomGanttIssue($project);
    $page = Livewire::actingAs(zoomGanttViewer($project))->test('gantt.index', ['project' => $project]);

    $page->assertSet('zoom', 2)->assertSeeHtml('data-gantt-weeks')->assertDontSeeHtml('data-gantt-days');
    $page->call('zoomOut')->assertSet('zoom', 1)->assertDontSeeHtml('data-gantt-weeks');
    $page->call('zoomOut')->assertSet('zoom', 1);
    $page->call('zoomIn')->call('zoomIn')->assertSet('zoom', 3)->assertSeeHtml('data-gantt-days');
    $page->call('zoomIn')->call('zoomIn')->assertSet('zoom', 4)->assertSeeHtml('data-gantt-zoom="4"');
});

test('the cross-project chart has the same zoom', function () {
    $project = Project::factory()->create();
    zoomGanttIssue($project);

    Livewire::actingAs(zoomGanttViewer($project))->test('gantt.global-index')
        ->assertSeeHtml('data-gantt-weeks')
        ->call('zoomIn')
        ->assertSeeHtml('data-gantt-days');
});

test('a bar behind schedule shows its late part', function () {
    $project = Project::factory()->create();
    zoomGanttIssue($project, ['start_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'done_ratio' => 10]);

    Livewire::actingAs(zoomGanttViewer($project))->test('gantt.index', ['project' => $project])->assertSeeHtml('data-gantt-late');
});

test('the late part runs from the start to today once the done ratio falls behind, as in Redmine', function () {
    $rows = collect([zoomGanttRow('2026-01-01', '2026-01-10', 0)]);
    $chart = new GanttChart($rows, collect(), 0);
    $row = $rows->first();

    expect($chart->lateWidthPercent($row, Carbon::parse('2025-12-31')))->toBe(0.0)
        ->and($chart->lateWidthPercent($row, Carbon::parse('2026-01-05')))->toBe(50.0)
        ->and($chart->lateWidthPercent($row, Carbon::parse('2026-02-01')))->toBe(100.0)
        // Half done by the 5th: on schedule, nothing late.
        ->and($chart->lateWidthPercent(zoomGanttRow('2026-01-01', '2026-01-10', 50), Carbon::parse('2026-01-05')))->toBe(0.0)
        ->and($chart->lateWidthPercent(zoomGanttRow('2026-01-01', '2026-01-10', 50), Carbon::parse('2026-01-06')))->toBe(60.0);
});

test('week bands follow ISO weeks and the day bands cover every day', function () {
    $chart = new GanttChart(collect([zoomGanttRow('2026-01-01', '2026-01-14', 0)]), collect(), 0);

    expect(array_column($chart->weekBands(), 'label'))->toBe(['1', '2', '3'])
        ->and($chart->dayBands())->toHaveCount(14);
});
