<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function bulkMoveMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function bulkMoveIssue(Project $project, Tracker $tracker): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a user with move_issues can bulk move selected issues to another project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = bulkMoveMember($source, ['view_issues', 'move_issues']);
    bulkMoveMember($target, ['view_issues', 'add_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $issueA = bulkMoveIssue($source, $sourceTracker);
    $issueB = bulkMoveIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issueA->id, $issueB->id])
        ->set('bulkMoveToProjectId', $target->id)
        ->set('bulkMoveToTrackerId', $targetTracker->id)
        ->call('applyBulkMove');

    expect($issueA->fresh()->project_id)->toBe($target->id)
        ->and($issueB->fresh()->project_id)->toBe($target->id)
        ->and($issueA->fresh()->tracker_id)->toBe($targetTracker->id);
});

test('a user without move_issues cannot bulk move issues', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);

    $user = bulkMoveMember($source, ['view_issues']);
    $issue = bulkMoveIssue($source, $tracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkMoveToProjectId', $target->id)
        ->call('applyBulkMove')
        ->assertForbidden();

    expect($issue->fresh()->project_id)->toBe($source->id);
});

test('bulk move is not offered when there is no eligible target project', function () {
    $source = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);

    $user = bulkMoveMember($source, ['view_issues', 'move_issues']);
    $issue = bulkMoveIssue($source, $tracker);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id]);

    expect($component->get('bulkMoveTargetProjects'))->toBeEmpty();
});
