<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function hoursMember(Project $project, array $permissions = ['view_project', 'view_issues', 'view_time_entries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function hoursIssue(Project $project, array $attributes = []): Issue
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

test('estimated remaining hours follow the done ratio', function () {
    $project = Project::factory()->create();

    expect(hoursIssue($project, ['estimated_hours' => 10, 'done_ratio' => 30])->estimatedRemainingHours())->toBe(7.0)
        ->and(hoursIssue($project, ['estimated_hours' => 10, 'done_ratio' => 100])->estimatedRemainingHours())->toBe(0.0)
        ->and(hoursIssue($project, ['estimated_hours' => null, 'done_ratio' => 0])->estimatedRemainingHours())->toBe(0.0);
});

test('the list shows the hour columns for parents and leaves', function () {
    $project = Project::factory()->create();
    $viewer = hoursMember($project);
    $parent = hoursIssue($project, ['subject' => 'Parent', 'estimated_hours' => 4, 'done_ratio' => 50]);
    $child = hoursIssue($project, ['subject' => 'Child', 'estimated_hours' => 6, 'parent_id' => $parent->id, 'done_ratio' => 0]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $parent->id, 'hours' => 1.5]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $child->id, 'hours' => 2]);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'estimated_hours', 'total_estimated_hours', 'estimated_remaining_hours', 'spent_hours', 'total_spent_hours'])
        ->set('statusFilter', 'all');
    $rows = $list->get('issues')->getCollection()->keyBy('subject');
    $cell = fn (string $subject, string $column) => $list->instance()->columnValue($rows[$subject], $column);

    expect($cell('Parent', 'estimated_hours'))->toBe('4.00')
        ->and($cell('Parent', 'total_estimated_hours'))->toBe('10.00')
        ->and($cell('Parent', 'estimated_remaining_hours'))->toBe('2.00')
        ->and($cell('Parent', 'spent_hours'))->toBe('1.50')
        ->and($cell('Parent', 'total_spent_hours'))->toBe('3.50')
        ->and($cell('Child', 'total_estimated_hours'))->toBe('6.00')
        ->and($cell('Child', 'spent_hours'))->toBe('2.00');
    $list->assertSee('合計作業時間');
});

test('an issue without an estimate shows blank estimate columns and the CSV carries the columns', function () {
    $project = Project::factory()->create();
    $viewer = hoursMember($project);
    hoursIssue($project, ['subject' => 'No estimate', 'estimated_hours' => null]);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'estimated_hours', 'estimated_remaining_hours', 'spent_hours'])
        ->set('statusFilter', 'all');
    $issue = $list->get('issues')->getCollection()->first();

    expect($list->instance()->columnValue($issue, 'estimated_hours'))->toBe('')
        ->and($list->instance()->columnValue($issue, 'estimated_remaining_hours'))->toBe('')
        ->and($list->instance()->columnValue($issue, 'spent_hours'))->toBe('0.00');

    $list->call('exportCsv')->assertFileDownloaded();
});

test('the issue page shows the remaining estimate', function () {
    $project = Project::factory()->create();
    $viewer = hoursMember($project);
    $issue = hoursIssue($project, ['estimated_hours' => 8, 'done_ratio' => 25]);

    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('残り工数(予定)')->assertSee('6.00 時間');
});

test('the API exposes estimates to everyone and logged time only with view_time_entries', function () {
    $project = Project::factory()->create();
    $timeViewer = hoursMember($project);
    $noTime = hoursMember($project, ['view_project', 'view_issues']);
    $parent = hoursIssue($project, ['estimated_hours' => 4, 'done_ratio' => 50]);
    hoursIssue($project, ['estimated_hours' => 6, 'parent_id' => $parent->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $parent->id, 'hours' => 1.5]);

    Passport::actingAs($timeViewer);
    $shown = $this->getJson("/api/v1/issues/{$parent->id}")->assertOk()->json('data');
    expect($shown['estimated_hours'])->toEqual(4.0)
        ->and($shown['total_estimated_hours'])->toEqual(10.0)
        ->and($shown['estimated_remaining_hours'])->toEqual(2.0)
        ->and($shown['spent_hours'])->toEqual(1.5)
        ->and($shown['total_spent_hours'])->toEqual(1.5);

    $listed = collect($this->getJson("/api/v1/projects/{$project->id}/issues")->assertOk()->json('data'))->firstWhere('id', $parent->id);
    expect($listed['spent_hours'])->toEqual(1.5)->and($listed['total_estimated_hours'])->toEqual(10.0);

    Passport::actingAs($noTime);
    $hidden = $this->getJson("/api/v1/issues/{$parent->id}")->assertOk()->json('data');
    expect($hidden['estimated_hours'])->toEqual(4.0)
        ->and($hidden['spent_hours'])->toBeNull()
        ->and($hidden['total_spent_hours'])->toBeNull();
});
