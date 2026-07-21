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

function subtaskProjectMember(Project $project, array $permissions = ['view_issues', 'edit_issues', 'add_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function subtaskIssue(Project $project, ?int $parentId = null): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'parent_id' => $parentId,
    ]);
}

test('a parent can be assigned to an issue through the form', function () {
    $project = Project::factory()->create();
    $user = subtaskProjectMember($project);
    $parent = subtaskIssue($project);
    $child = subtaskIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $child])
        ->set('parent_id', $parent->id)
        ->call('save')
        ->assertRedirect();

    expect($child->fresh()->parent_id)->toBe($parent->id);
});

test('an issue cannot be its own parent', function () {
    $project = Project::factory()->create();
    $user = subtaskProjectMember($project);
    $issue = subtaskIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('parent_id', $issue->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('a descendant cannot be set as its own ancestor parent', function () {
    $project = Project::factory()->create();
    $user = subtaskProjectMember($project);
    $grandparent = subtaskIssue($project);
    $parent = subtaskIssue($project, $grandparent->id);
    $child = subtaskIssue($project, $parent->id);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $grandparent])
        ->set('parent_id', $child->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('a parent from another project is rejected', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = subtaskProjectMember($project);
    $foreignIssue = subtaskIssue($otherProject);
    $issue = subtaskIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('parent_id', $foreignIssue->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('the issue show page lists subtasks and links to the parent', function () {
    $project = Project::factory()->create();
    $user = subtaskProjectMember($project);
    $parent = subtaskIssue($project);
    $child = subtaskIssue($project, $parent->id);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSee($child->subject);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $child])
        ->assertSee($parent->subject);
});
