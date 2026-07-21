<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Livewire\Livewire;

function rollupService(): IssueService
{
    return app(IssueService::class);
}

function rollupIssue(Project $project, Tracker $tracker, IssueStatus $status, Enumeration $priority, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create(array_merge([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ], $attributes));
}

test('a parent priority becomes the highest position among its open children', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $openStatus = IssueStatus::factory()->create();
    $low = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'Low']);
    $high = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'High']);

    $parent = rollupIssue($project, $tracker, $openStatus, $low);
    rollupIssue($project, $tracker, $openStatus, $low, ['parent_id' => $parent->id]);
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $openStatus->id,
        'priority_id' => $high->id, 'subject' => 'Urgent subtask', 'parent_id' => $parent->id,
    ], $actor);

    expect($parent->fresh()->priority_id)->toBe($high->id);
});

test('a parent priority falls back to the default when every child is closed', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $closedStatus = IssueStatus::factory()->closed()->create();
    $low = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $high = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $default = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'is_default' => true]);

    $parent = rollupIssue($project, $tracker, $closedStatus, $high);
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $closedStatus->id,
        'priority_id' => $low->id, 'subject' => 'Closed subtask', 'parent_id' => $parent->id,
    ], $actor);

    expect($parent->fresh()->priority_id)->toBe($default->id);
});

test('a parent start/due date spans the earliest and latest among its children', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    $parent = rollupIssue($project, $tracker, $status, $priority);
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $priority->id, 'subject' => 'Early', 'parent_id' => $parent->id,
        'start_date' => '2026-01-01', 'due_date' => '2026-01-10',
    ], $actor);
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $priority->id, 'subject' => 'Late', 'parent_id' => $parent->id,
        'start_date' => '2026-02-01', 'due_date' => '2026-03-01',
    ], $actor);

    expect($parent->fresh()->start_date->toDateString())->toBe('2026-01-01')
        ->and($parent->fresh()->due_date->toDateString())->toBe('2026-03-01');
});

test('a parent done_ratio is the estimated-hours-weighted average of its children, with closed children counted as 100%', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $openStatus = IssueStatus::factory()->create();
    $closedStatus = IssueStatus::factory()->closed()->create();
    $priority = Enumeration::factory()->create();

    $parent = rollupIssue($project, $tracker, $openStatus, $priority);
    // 8 estimated hours at 50% = 4 "weighted done" units.
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $openStatus->id,
        'priority_id' => $priority->id, 'subject' => 'In progress', 'parent_id' => $parent->id,
        'estimated_hours' => 8, 'done_ratio' => 50,
    ], $actor);
    // 8 estimated hours, closed => counted as 100% => 8 "weighted done" units.
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $closedStatus->id,
        'priority_id' => $priority->id, 'subject' => 'Closed', 'parent_id' => $parent->id,
        'estimated_hours' => 8, 'done_ratio' => 0,
    ], $actor);

    // (8*50 + 8*100) / (8+8) = 75
    expect($parent->fresh()->done_ratio)->toBe(75);
});

test('a child with no estimate is weighted as the average estimate among children that have one', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    $parent = rollupIssue($project, $tracker, $status, $priority);
    // Estimated at 10 hours, 100% done => weight 10, contributes 1000.
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $priority->id, 'subject' => 'Estimated', 'parent_id' => $parent->id,
        'estimated_hours' => 10, 'done_ratio' => 100,
    ], $actor);
    // No estimate, 0% done => weighted as the average estimate (10), contributes 0.
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $priority->id, 'subject' => 'Unestimated', 'parent_id' => $parent->id,
        'done_ratio' => 0,
    ], $actor);

    // average estimate = 10; (10*100 + 10*0) / (10*2) = 50
    expect($parent->fresh()->done_ratio)->toBe(50);
});

test('disabling parent_issue_priority stops priority from being derived', function () {
    Setting::set('parent_issue_priority', false);

    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $low = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $high = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);

    $parent = rollupIssue($project, $tracker, $status, $low);
    rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $high->id, 'subject' => 'Subtask', 'parent_id' => $parent->id,
    ], $actor);

    expect($parent->fresh()->priority_id)->toBe($low->id);
});

test('reparenting a child recalculates both the old and new parent', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    $oldParent = rollupIssue($project, $tracker, $status, $priority);
    $newParent = rollupIssue($project, $tracker, $status, $priority);
    $child = rollupService()->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $status->id,
        'priority_id' => $priority->id, 'subject' => 'Movable subtask', 'parent_id' => $oldParent->id,
        'start_date' => '2026-05-01', 'due_date' => '2026-05-10',
    ], $actor);

    expect($oldParent->fresh()->start_date->toDateString())->toBe('2026-05-01');

    rollupService()->update($child, ['parent_id' => $newParent->id], $actor);

    expect($newParent->fresh()->start_date->toDateString())->toBe('2026-05-01')
        ->and($oldParent->fresh()->start_date)->toBeNull();
});

test('the issue form disables the derived fields for a parent issue and shows an explanation', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $parent = rollupIssue($project, $tracker, $status, $priority);
    rollupIssue($project, $tracker, $status, $priority, ['parent_id' => $parent->id]);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $parent]);

    expect($component->get('priorityIsDerived'))->toBeTrue()
        ->and($component->get('datesAreDerived'))->toBeTrue()
        ->and($component->get('doneRatioIsParentDerived'))->toBeTrue();

    $component->assertSee('自動算出されます');
});

test('the issue form does not disable derived fields for a leaf issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $leaf = rollupIssue($project, $tracker, $status, $priority);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $leaf]);

    expect($component->get('priorityIsDerived'))->toBeFalse()
        ->and($component->get('datesAreDerived'))->toBeFalse()
        ->and($component->get('doneRatioIsParentDerived'))->toBeFalse();
});
