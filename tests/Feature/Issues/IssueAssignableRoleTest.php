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

test('a member with a non-assignable role is excluded from the assignee dropdown', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $assignableRole = Role::factory()->create(['assignable' => true]);
    $nonAssignableRole = Role::factory()->create(['assignable' => false]);

    $assignableUser = User::factory()->create();
    Member::factory()->for($project)->for($assignableUser)->create()->roles()->attach($assignableRole);

    $nonAssignableUser = User::factory()->create();
    Member::factory()->for($project)->for($nonAssignableUser)->create()->roles()->attach($nonAssignableRole);

    $viewer = User::factory()->create();
    $viewerRole = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($viewerRole);

    $component = Livewire::actingAs($viewer)->test('issues.form', ['project' => $project]);

    $memberIds = $component->get('projectMembers')->pluck('id');

    expect($memberIds)->toContain($assignableUser->id)
        ->not->toContain($nonAssignableUser->id);
});

test('a member holding both an assignable and a non-assignable role is still eligible', function () {
    $project = Project::factory()->create();
    $assignableRole = Role::factory()->create(['assignable' => true]);
    $nonAssignableRole = Role::factory()->create(['assignable' => false]);

    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach([$assignableRole->id, $nonAssignableRole->id]);

    expect($project->assignableUsers()->pluck('id'))->toContain($user->id);
});

test('an existing assignment to a now-non-assignable member is preserved on unrelated saves', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues'], 'assignable' => false]);
    $assignee = User::factory()->create();
    Member::factory()->for($project)->for($assignee)->create()->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'assigned_to_id' => $assignee->id,
    ]);

    $editor = User::factory()->create();
    $editorRole = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($editor)->create()->roles()->attach($editorRole);

    Livewire::actingAs($editor)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', 'Unrelated change')
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->assigned_to_id)->toBe($assignee->id);
});
