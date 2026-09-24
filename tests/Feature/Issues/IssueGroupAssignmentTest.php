<?php

use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Issues\AssigneeChoice;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function groupAssignMember(Project $project, array $permissions = ['view_issues', 'add_issues', 'edit_issues'], bool $assignable = true): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'assignable' => $assignable]));

    return $user;
}

function groupAssignMemberGroup(Project $project, bool $assignable = true): Group
{
    $group = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => $assignable]));

    return $group;
}

function groupAssignIssue(Project $project, array $attributes = []): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
        ...$attributes,
    ]);
}

test('groups are not offered as assignees while group assignment is off', function () {
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertDontSee($group->name)
        ->set('assigneeChoice', AssigneeChoice::forGroup($group))
        ->call('save')
        ->assertHasErrors('assigned_to_group_id');

    expect($issue->fresh()->assigned_to_group_id)->toBeNull();
});

test('with group assignment on, a member group holding an assignable role can be picked', function () {
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $notAssignable = groupAssignMemberGroup($project, assignable: false);
    $issue = groupAssignIssue($project, ['assigned_to_id' => $editor->id]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSee($group->name)
        ->assertDontSee($notAssignable->name)
        ->set('assigneeChoice', AssigneeChoice::forGroup($group))
        ->assertSet('assigned_to_id', null)
        ->assertSet('assigned_to_group_id', $group->id)
        ->call('save')
        ->assertHasNoErrors();

    $issue->refresh();
    expect($issue->assigned_to_group_id)->toBe($group->id)
        ->and($issue->assigned_to_id)->toBeNull()
        ->and($issue->assigneeName())->toBe($group->name)
        ->and($issue->journals()->first()->details()->where('prop_key', 'assigned_to_group_id')->value('new_value'))->toBe((string) $group->id);
});

test('a group that is not an assignable member of the project is rejected', function () {
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $outsider = Group::factory()->create();
    $issue = groupAssignIssue($project);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('assigned_to_group_id', $outsider->id)
        ->call('save')
        ->assertHasErrors('assigned_to_group_id');
});

test('turning the setting off keeps existing group assignments shown and savable', function () {
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $other = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project, ['assigned_to_group_id' => $group->id]);

    Livewire::actingAs($editor)->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee($group->name);

    Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->assertSee($group->name);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSet('assigneeChoice', AssigneeChoice::forGroup($group))
        ->assertSee($group->name)
        ->assertDontSee($other->name)
        ->set('subject', 'Still assigned to the group')
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->assigned_to_group_id)->toBe($group->id);
});

test('assigning a user replaces a group assignee', function () {
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project, ['assigned_to_group_id' => $group->id]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('assigned_to_id', $editor->id)
        ->assertSet('assigned_to_group_id', null)
        ->assertSet('assigneeChoice', (string) $editor->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh())->assigned_to_id->toBe($editor->id)->assigned_to_group_id->toBeNull();
});

test('an issue is never saved with both a user and a group assignee', function () {
    $project = Project::factory()->create();
    $user = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project, ['assigned_to_group_id' => $group->id]);

    $issue->update(['assigned_to_id' => $user->id]);
    expect($issue->fresh())->assigned_to_id->toBe($user->id)->assigned_to_group_id->toBeNull();

    expect(fn () => groupAssignIssue($project, ['assigned_to_id' => $user->id, 'assigned_to_group_id' => $group->id]))
        ->toThrow(LogicException::class);
});

test('deleting a group unassigns its issues', function () {
    $project = Project::factory()->create();
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project, ['assigned_to_group_id' => $group->id]);

    $group->delete();

    expect($issue->fresh()->assigned_to_group_id)->toBeNull();
});

test('bulk edit and the context menu can assign a group', function () {
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $first = groupAssignIssue($project, ['assigned_to_id' => $editor->id]);
    $second = groupAssignIssue($project);

    Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->set('selected', [$first->id, $second->id])
        ->set('bulkAssigneeChoice', AssigneeChoice::forGroup($group))
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    expect($first->fresh())->assigned_to_group_id->toBe($group->id)->assigned_to_id->toBeNull()
        ->and($second->fresh()->assigned_to_group_id)->toBe($group->id);

    Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->set('selected', [$first->id])
        ->call('contextUpdate', 'assigned_to_id', 'none');

    expect($first->fresh())->assigned_to_group_id->toBeNull()->assigned_to_id->toBeNull();

    Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->set('selected', [$first->id])
        ->call('contextUpdate', 'assigned_to_id', AssigneeChoice::forGroup($group));

    expect($first->fresh()->assigned_to_group_id)->toBe($group->id);
});

test('bulk edit rejects a group while group assignment is off', function () {
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project);

    Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkAssigneeChoice', AssigneeChoice::forGroup($group))
        ->call('applyBulkEdit')
        ->assertHasErrors('bulkAssignedToGroupId');

    expect($issue->fresh()->assigned_to_group_id)->toBeNull();
});

test('the api assigns a group and returns it as the assignee', function () {
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project, ['assigned_to_id' => $editor->id]);

    Passport::actingAs($editor);

    $this->putJson("/api/v1/issues/{$issue->id}", ['assigned_to_id' => $editor->id, 'assigned_to_group_id' => $group->id])
        ->assertUnprocessable();

    $this->putJson("/api/v1/issues/{$issue->id}", ['assigned_to_group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_to_id', null)
        ->assertJsonPath('data.assigned_to_group_id', $group->id)
        ->assertJsonPath('data.assigned_to', ['id' => $group->id, 'name' => $group->name, 'type' => 'group']);

    $this->getJson("/api/v1/projects/{$project->id}/issues")
        ->assertOk()
        ->assertJsonPath('data.0.assigned_to.type', 'group');

    $this->putJson("/api/v1/issues/{$issue->id}", ['assigned_to_id' => null])->assertOk();
    expect($issue->fresh())->assigned_to_id->toBeNull()->assigned_to_group_id->toBeNull();
});

test('the api rejects a group assignee while group assignment is off', function () {
    $project = Project::factory()->create();
    $editor = groupAssignMember($project);
    $group = groupAssignMemberGroup($project);
    $issue = groupAssignIssue($project);

    Passport::actingAs($editor);

    $this->putJson("/api/v1/issues/{$issue->id}", ['assigned_to_group_id' => $group->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assigned_to_group_id');
});

test('the setting is saved from the administration settings page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('issue_group_assignment', false)
        ->set('issue_group_assignment', true)
        ->call('save');

    expect(Setting::get('issue_group_assignment'))->toBeTrue();
});
