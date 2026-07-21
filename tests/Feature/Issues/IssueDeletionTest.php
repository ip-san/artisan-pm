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

function deletionProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function deletableIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a member with delete_issues can delete an issue and is redirected to the list', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteIssue')
        ->assertRedirect(route('issues.index', $project));

    expect(Issue::find($issue->id))->toBeNull();
});

test('a user without delete_issues cannot delete an issue', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues']);
    $issue = deletableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteIssue')
        ->assertForbidden();

    expect(Issue::find($issue->id))->not->toBeNull();
});

test('deleting an issue orphans its time entries instead of deleting them', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteIssue');

    expect($entry->fresh()->issue_id)->toBeNull();
});

test('deleting a parent issue orphans its children instead of deleting them', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $parent = deletableIssue($project);
    $child = deletableIssue($project);
    $child->update(['parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->call('deleteIssue');

    expect($child->fresh()->parent_id)->toBeNull();
});
