<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\WorkflowTransition;
use Livewire\Livewire;

function bulkEditMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a user with edit_issues can bulk-set priority and assignee across selected issues', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = bulkEditMember($project, ['view_issues', 'edit_issues']);

    $priority = Enumeration::factory()->create();
    $issueA = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issueB = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issueA->id, $issueB->id])
        ->set('bulkPriorityId', $priority->id)
        ->set('bulkAssignedToId', $user->id)
        ->call('applyBulkEdit');

    expect($issueA->fresh()->priority_id)->toBe($priority->id)
        ->and($issueA->fresh()->assigned_to_id)->toBe($user->id)
        ->and($issueB->fresh()->priority_id)->toBe($priority->id);
});

test('bulk edit records one journal entry per changed issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = bulkEditMember($project, ['view_issues', 'edit_issues']);

    $version = Version::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkFixedVersionId', $version->id)
        ->set('bulkComment', '一括でバージョンを設定しました')
        ->call('applyBulkEdit');

    $journal = $issue->fresh()->journals()->firstOrFail();

    expect($journal->notes)->toBe('一括でバージョンを設定しました')
        ->and($journal->details()->where('prop_key', 'fixed_version_id')->exists())->toBeTrue();
});

test('bulk status change is only offered when every selected issue shares the same status', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $statusA = IssueStatus::factory()->create(['name' => 'New']);
    $statusB = IssueStatus::factory()->create(['name' => 'In Progress']);

    $sameStatusIssues = Issue::factory()->for($project)->count(2)->create(['tracker_id' => $tracker->id, 'status_id' => $statusA->id]);
    $mixedIssue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $statusB->id]);

    WorkflowTransition::create([
        'tracker_id' => $tracker->id,
        'role_id' => $role->id,
        'old_status_id' => $statusA->id,
        'new_status_id' => $statusB->id,
    ]);

    $sameStatusComponent = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', $sameStatusIssues->pluck('id')->all());

    expect($sameStatusComponent->get('bulkStatusOptions')->pluck('id'))->toContain($statusB->id);

    $mixedComponent = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [...$sameStatusIssues->pluck('id')->all(), $mixedIssue->id]);

    expect($mixedComponent->get('bulkStatusOptions'))->toBeEmpty();
});

test('a user without edit_issues cannot apply a bulk edit', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = bulkEditMember($project, ['view_issues']);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $priority = Enumeration::factory()->create();

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkPriorityId', $priority->id)
        ->call('applyBulkEdit')
        ->assertForbidden();

    expect($issue->fresh()->priority_id)->not->toBe($priority->id);
});

test('bulk edit does not touch issues outside the current project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $user = bulkEditMember($project, ['view_issues', 'edit_issues']);

    $ownIssue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $foreignIssue = Issue::factory()->for($otherProject)->create(['tracker_id' => $tracker->id]);
    $priority = Enumeration::factory()->create();

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$ownIssue->id, $foreignIssue->id])
        ->set('bulkPriorityId', $priority->id)
        ->call('applyBulkEdit');

    expect($ownIssue->fresh()->priority_id)->toBe($priority->id)
        ->and($foreignIssue->fresh()->priority_id)->not->toBe($priority->id);
});
