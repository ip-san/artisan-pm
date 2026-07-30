<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function timeEntryReportMember(Project $project, array $permissions = ['log_time', 'view_time_entries'], string $timeEntriesVisibility = 'all'): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'time_entries_visibility' => $timeEntriesVisibility]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a user without view_time_entries cannot open the report', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->assertForbidden();
});

test('a single row criterion pivots hours across the month period', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $other = timeEntryReportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 2]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-02-05', 'hours' => 3]);
    TimeEntry::factory()->for($project)->for($other)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-20', 'hours' => 1]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user'])
        ->set('period', 'month');

    $report = $component->instance()->report;

    expect($report->periods)->toHaveCount(2)
        ->and($report->periods[0]['key'])->toBe('2026-01')
        ->and($report->periods[1]['key'])->toBe('2026-02')
        ->and($report->grandTotal)->toBe(6.0)
        ->and($report->columnTotals['2026-01'])->toBe(3.0)
        ->and($report->columnTotals['2026-02'])->toBe(3.0);

    $userRow = collect($report->rows)->first(fn ($row) => $row['labels'][0] === $user->name);
    expect($userRow['cells']['2026-01'])->toBe(2.0)
        ->and($userRow['cells']['2026-02'])->toBe(3.0)
        ->and($userRow['total'])->toBe(5.0);
});

test('two row criteria produce one row per distinct combination', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    $issueA = Issue::factory()->for($project)->for($tracker)->for($status, 'status')->create();
    $issueB = Issue::factory()->for($project)->for($tracker)->for($status, 'status')->create();

    TimeEntry::factory()->for($project)->for($user)->for($issueA)->create(['activity_id' => $activity->id, 'spent_on' => '2026-03-01', 'hours' => 1]);
    TimeEntry::factory()->for($project)->for($user)->for($issueB)->create(['activity_id' => $activity->id, 'spent_on' => '2026-03-02', 'hours' => 4]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user', 'tracker'])
        ->set('period', 'day');

    $report = $component->instance()->report;

    expect($report->rows)->toHaveCount(1);
    expect($report->rows[0]['labels'])->toBe([$user->name, $tracker->name]);
    expect($report->rows[0]['total'])->toBe(5.0);
});

test('a time entry with no issue still counts toward the total under an issue-backed criterion', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $issue = Issue::factory()->for($project)->for($tracker)->for($status, 'status')->create();

    TimeEntry::factory()->for($project)->for($user)->for($issue)->create(['activity_id' => $activity->id, 'spent_on' => '2026-04-01', 'hours' => 2]);
    // No ->for($issue) here — issue_id stays null, exercising the leftJoin's
    // NULL side rather than the join match every other test in this file uses.
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-04-02', 'hours' => 3]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['status']);

    $report = $component->instance()->report;

    expect($report->grandTotal)->toBe(5.0);

    $noIssueRow = collect($report->rows)->first(fn ($row) => $row['labels'][0] === '(なし)');
    expect($noIssueRow)->not->toBeNull()
        ->and($noIssueRow['total'])->toBe(3.0);
});

test('the week period buckets by ISO week across a year boundary', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    // 2025-12-30 and 2026-01-01 both fall in ISO week 2026-W01 (their ISO
    // week-year is 2026, not each date's own calendar year) — a naive
    // "$date->year" bucket would split these into two different periods.
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2025-12-30', 'hours' => 1]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-01', 'hours' => 2]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user'])
        ->set('period', 'week');

    $report = $component->instance()->report;

    expect($report->periods)->toHaveCount(1)
        ->and($report->periods[0]['key'])->toBe('2026-01')
        ->and($report->grandTotal)->toBe(3.0);
});

test('the year period buckets by calendar year', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2025-06-01', 'hours' => 1]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-06-01', 'hours' => 4]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user'])
        ->set('period', 'year');

    $report = $component->instance()->report;

    expect($report->periods)->toHaveCount(2)
        ->and($report->periods[0]['key'])->toBe('2025')
        ->and($report->periods[1]['key'])->toBe('2026')
        ->and($report->columnTotals['2025'])->toBe(1.0)
        ->and($report->columnTotals['2026'])->toBe(4.0);
});

test('a 4th selected criterion is ignored, capping at 3 like Redmine', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $issue = Issue::factory()->for($project)->for($tracker)->for($status, 'status')->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->for($issue)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 1]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user', 'activity', 'status', 'tracker']);

    expect($component->instance()->selectedCriteria)->toHaveCount(3);

    // Build the report itself, not just the criterion count, so the 4th
    // and 5th criteria (activity/status/tracker) actually reach the
    // builder at least once — the count assertion alone never calls build().
    $report = $component->instance()->report;
    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]['labels'])->toHaveCount(3);
});

test('hours logged under a global activity before it was overridden stay a separate row from its override', function () {
    // The discriminating case for the checklist's COALESCE-omission claim:
    // both entries are logged against the SAME logical activity (the
    // override's parent_id points at the global row), not two unrelated
    // activities — Redmine's COALESCE(parent_id, id) would merge these
    // into one row; this app's plain activity_id grouping does not, since
    // TimeEntry.activity_id is a hard FK to whichever specific row was
    // picked at logging time.
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $globalActivity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Development']);
    $overrideActivity = Enumeration::factory()->create([
        'type' => EnumerationType::TimeEntryActivity->value,
        'name' => 'Development (override)',
        'project_id' => $project->id,
        'parent_id' => $globalActivity->id,
    ]);

    // Entered while the global activity was still selectable (before the
    // override existed, or directly via the API/import path).
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $globalActivity->id, 'spent_on' => '2026-01-10', 'hours' => 2]);
    // Entered afterward, once Project::activities() only offers the override.
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $overrideActivity->id, 'spent_on' => '2026-01-11', 'hours' => 3]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['activity']);

    $report = $component->instance()->report;

    // Two rows, not one — the app does NOT coalesce a global activity with
    // its own project-specific override the way Redmine does. Both hours
    // still count toward the grand total, so nothing is silently dropped.
    expect($report->rows)->toHaveCount(2)
        ->and($report->grandTotal)->toBe(5.0);

    $labels = collect($report->rows)->pluck('labels.0')->all();
    expect($labels)->toContain('Development')->toContain('Development (override)');
});

test('the issue criterion labels each row with the issue number and subject', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $issue = Issue::factory()->for($project)->for($tracker)->for($status, 'status')->create(['subject' => 'Fix the login bug']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->for($issue)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 1]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['issue']);

    $report = $component->instance()->report;

    expect($report->rows[0]['labels'][0])->toBe("#{$issue->id} Fix the login bug");
});

test('a user with the Own time-entry visibility only sees their own hours in the report', function () {
    $project = Project::factory()->create();
    $viewer = timeEntryReportMember($project, ['log_time', 'view_time_entries'], 'own');
    $other = timeEntryReportMember($project, ['log_time', 'view_time_entries']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    TimeEntry::factory()->for($project)->for($viewer)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 2]);
    TimeEntry::factory()->for($project)->for($other)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 9]);

    $component = Livewire::actingAs($viewer)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user']);

    $report = $component->instance()->report;

    expect($report->grandTotal)->toBe(2.0);
});

test('no rows are shown until a row criterion is selected', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'spent_on' => '2026-01-10', 'hours' => 2]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.report', ['project' => $project])
        ->set('criteria', []);

    expect($component->instance()->report->isEmpty())->toBeTrue();
});

test('a real HTTP request renders the report page', function () {
    $project = Project::factory()->create();
    $user = timeEntryReportMember($project);

    $this->actingAs($user)
        ->get(route('time-entries.report', $project))
        ->assertOk()
        ->assertSee('工数レポート');
});
