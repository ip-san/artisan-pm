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
use Livewire\Livewire;

function estimatedHoursIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create(array_merge([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ], $attributes));
}

test('a leaf issue reports its own estimated and spent hours', function () {
    $project = Project::factory()->create();
    $issue = estimatedHoursIssue($project, ['estimated_hours' => 5.5]);
    TimeEntry::factory()->for($project)->for($issue)->create(['hours' => 2]);
    TimeEntry::factory()->for($project)->for($issue)->create(['hours' => 1.5]);

    expect($issue->isLeaf())->toBeTrue()
        ->and((float) $issue->estimated_hours)->toBe(5.5)
        ->and($issue->totalEstimatedHours())->toBe(5.5)
        ->and($issue->spentHours())->toBe(3.5)
        ->and($issue->totalSpentHours())->toBe(3.5);
});

test('a parent issue totals estimated and spent hours across all descendants', function () {
    $project = Project::factory()->create();
    $parent = estimatedHoursIssue($project, ['estimated_hours' => 2]);
    $child = estimatedHoursIssue($project, ['parent_id' => $parent->id, 'estimated_hours' => 3]);
    $grandchild = estimatedHoursIssue($project, ['parent_id' => $child->id, 'estimated_hours' => 4]);

    TimeEntry::factory()->for($project)->for($parent)->create(['hours' => 1]);
    TimeEntry::factory()->for($project)->for($child)->create(['hours' => 2]);
    TimeEntry::factory()->for($project)->for($grandchild)->create(['hours' => 3]);

    expect($parent->isLeaf())->toBeFalse()
        ->and($parent->descendantIds()->sort()->values()->all())->toBe([$child->id, $grandchild->id])
        ->and($parent->totalEstimatedHours())->toBe(9.0)
        ->and($parent->totalSpentHours())->toBe(6.0);
});

test('a leaf issue with no estimate reports zero for the total', function () {
    $project = Project::factory()->create();
    $issue = estimatedHoursIssue($project);

    expect($issue->estimated_hours)->toBeNull()
        ->and($issue->totalEstimatedHours())->toBe(0.0)
        ->and($issue->totalSpentHours())->toBe(0.0);
});

test('the issue form saves an estimated hours value', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['default_status_id' => IssueStatus::factory()->create()->id]);
    $project->trackers()->attach($tracker);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', Enumeration::factory()->create()->id)
        ->set('subject', 'New issue')
        ->set('estimated_hours', '7.25')
        ->call('save');

    $issue = Issue::where('subject', 'New issue')->firstOrFail();

    expect((float) $issue->estimated_hours)->toBe(7.25);
});

test('clearing the estimated hours field on an existing issue stores null, not zero', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = estimatedHoursIssue($project, ['tracker_id' => $tracker->id, 'estimated_hours' => 3]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('estimated_hours', '')
        ->call('save');

    expect($issue->fresh()->estimated_hours)->toBeNull();
});

test('the issue show page displays the total estimated and spent hours for a parent issue', function () {
    $project = Project::factory()->create();
    $parent = estimatedHoursIssue($project, ['estimated_hours' => 2]);
    $child = estimatedHoursIssue($project, ['parent_id' => $parent->id, 'estimated_hours' => 3]);
    TimeEntry::factory()->for($project)->for($child)->create(['hours' => 4]);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'view_time_entries']])
    );

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSee('合計: 5.00 時間')
        ->assertSee('合計: 4.00 時間');
});
