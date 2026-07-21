<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\WorkflowTransition;
use Livewire\Livewire;

function projectMemberWithPermissions(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with add_issues can create an issue through the form', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);

    $user = projectMemberWithPermissions($project, ['view_issues', 'add_issues']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', $priority->id)
        ->set('subject', 'Something is broken')
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Something is broken')->firstOrFail();

    expect($issue->author_id)->toBe($user->id)
        ->and($issue->status_id)->toBe($status->id);
});

test('a user without add_issues cannot open the issue creation form', function () {
    $project = Project::factory()->create();
    $user = projectMemberWithPermissions($project, ['view_issues']);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertForbidden();
});

test('only workflow-allowed statuses are offered when editing an issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $new = IssueStatus::factory()->create(['name' => 'New']);
    $inProgress = IssueStatus::factory()->create(['name' => 'In Progress']);
    $closed = IssueStatus::factory()->closed()->create(['name' => 'Closed']);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $new->id, 'new_status_id' => $inProgress->id,
    ]);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $new->id]);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue]);

    $allowedIds = $component->get('allowedStatuses')->pluck('id')->all();

    expect($allowedIds)->toContain($new->id, $inProgress->id)
        ->not->toContain($closed->id);

    // Attempting to jump straight to Closed (not workflow-allowed) is denied.
    $component->set('status_id', $closed->id)->call('save')->assertForbidden();
});

test('updating an issue records a journal entry visible on the show page', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = projectMemberWithPermissions($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Old subject', 'tracker_id' => $tracker->id]);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', 'New subject')
        ->set('comment', 'Renamed for clarity')
        ->call('save')
        ->assertRedirect();

    $journal = Journal::where('issue_id', $issue->id)->firstOrFail();

    expect($journal->notes)->toBe('Renamed for clarity')
        ->and($journal->details()->where('prop_key', 'subject')->exists())->toBeTrue();
});

test('creating an issue rejects a tracker not attached to the project', function () {
    $project = Project::factory()->create();
    $foreignTracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);

    $user = projectMemberWithPermissions($project, ['view_issues', 'add_issues']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $foreignTracker->id)
        ->set('priority_id', $priority->id)
        ->set('subject', 'Should not be created')
        ->call('save')
        ->assertHasErrors(['tracker_id']);

    expect(Issue::where('subject', 'Should not be created')->exists())->toBeFalse();
});

test('editing an issue rejects a fixed_version_id belonging to another project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $user = projectMemberWithPermissions($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $foreignVersion = Version::factory()->for($otherProject)->create();

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('fixed_version_id', $foreignVersion->id)
        ->call('save')
        ->assertHasErrors(['fixed_version_id']);

    expect($issue->fresh()->fixed_version_id)->toBeNull();
});

test('editing an issue rejects an assignee who is not a member of the project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $user = projectMemberWithPermissions($project, ['view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $outsider = User::factory()->create();

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('assigned_to_id', $outsider->id)
        ->call('save')
        ->assertHasErrors(['assigned_to_id']);

    expect($issue->fresh()->assigned_to_id)->toBeNull();
});

test('a user can watch and unwatch an issue', function () {
    $project = Project::factory()->create();
    $user = projectMemberWithPermissions($project, ['view_issues', 'add_issue_watchers']);
    $issue = Issue::factory()->for($project)->create();

    $component = Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue]);

    expect($issue->fresh()->isWatchedBy($user))->toBeFalse();

    $component->call('toggleWatch');
    expect($issue->fresh()->isWatchedBy($user))->toBeTrue();

    $component->call('toggleWatch');
    expect($issue->fresh()->isWatchedBy($user))->toBeFalse();
});
