<?php

use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;
use Livewire\Livewire;

/**
 * @return array{project: Project, tracker: Tracker, role: Role, user: User, statuses: array<string, IssueStatus>}
 */
function newIssueWorkflow(): array
{
    $statuses = [
        'new' => IssueStatus::factory()->create(['name' => 'New', 'position' => 1]),
        'assigned' => IssueStatus::factory()->create(['name' => 'Assigned', 'position' => 2]),
        'draft' => IssueStatus::factory()->create(['name' => 'Draft', 'position' => 3]),
    ];
    $tracker = Tracker::factory()->create(['default_status_id' => $statuses['new']->id]);
    $project = Project::factory()->create();
    $project->trackers()->attach($tracker);
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'add_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    Enumeration::factory()->create(['is_default' => true]);

    return compact('project', 'tracker', 'role', 'user', 'statuses');
}

function newIssueRow(Tracker $tracker, Role $role, IssueStatus $target, array $flags = []): void
{
    WorkflowTransition::query()->create(['tracker_id' => $tracker->id, 'role_id' => $role->id, 'old_status_id' => null, 'new_status_id' => $target->id, 'author' => false, 'assignee' => false, ...$flags]);
}

test('without a new issue row the tracker default is the only starting status', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user, 'statuses' => $statuses] = newIssueWorkflow();

    expect(app(WorkflowService::class)->initialStatuses($project, $tracker, $user)->pluck('name')->all())->toBe(['New']);
});

test('the new issue row lists the statuses a role may start in', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['new']);
    newIssueRow($tracker, $role, $s['draft']);

    expect(app(WorkflowService::class)->initialStatuses($project, $tracker, $user)->pluck('name')->all())->toBe(['New', 'Draft']);
});

test('rows for the author apply to the creator, rows for the assignee do not', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['assigned'], ['author' => true]);
    newIssueRow($tracker, $role, $s['draft'], ['assignee' => true]);

    expect(app(WorkflowService::class)->initialStatuses($project, $tracker, $user)->pluck('name')->all())->toBe(['Assigned']);
});

test('another role\'s row and another tracker\'s row are ignored', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    $otherRole = Role::factory()->create();
    $otherTracker = Tracker::factory()->create();
    newIssueRow($tracker, $otherRole, $s['draft']);
    newIssueRow($otherTracker, Role::query()->first(), $s['draft']);

    expect(app(WorkflowService::class)->initialStatuses($project, $tracker, $user)->pluck('name')->all())->toBe(['New']);
});

test('an administrator may start in any status', function () {
    ['project' => $project, 'tracker' => $tracker] = newIssueWorkflow();

    expect(app(WorkflowService::class)->initialStatuses($project, $tracker, User::factory()->admin()->create())->pluck('name')->all())->toBe(['New', 'Assigned', 'Draft']);
});

test('the create form offers a status choice only when there is one', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])->set('tracker_id', $tracker->id)->assertDontSee('data-initial-status', false);

    newIssueRow($tracker, $role, $s['new']);
    newIssueRow($tracker, $role, $s['draft']);
    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project])->set('tracker_id', $tracker->id);
    $form->assertSee('data-initial-status', false)->assertSet('status_id', $s['new']->id);
});

test('an issue is created in the chosen allowed status', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['new']);
    newIssueRow($tracker, $role, $s['draft']);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Starts as draft')
        ->set('status_id', $s['draft']->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Issue::query()->where('subject', 'Starts as draft')->firstOrFail()->status_id)->toBe($s['draft']->id);
});

test('a status the workflow does not allow falls back to the default', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['new']);
    newIssueRow($tracker, $role, $s['draft']);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Tampered')
        ->set('status_id', $s['assigned']->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Issue::query()->where('subject', 'Tampered')->firstOrFail()->status_id)->toBe($s['new']->id);
});

test('when the tracker default is not allowed the first allowed status starts the form', function () {
    ['project' => $project, 'tracker' => $tracker, 'role' => $role, 'user' => $user, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['assigned']);
    newIssueRow($tracker, $role, $s['draft']);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])->set('tracker_id', $tracker->id)->assertSet('status_id', $s['assigned']->id);
});

test('the workflow editor edits and keeps the new issue row', function () {
    ['tracker' => $tracker, 'role' => $role, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['new']);
    $admin = User::factory()->admin()->create();

    $editor = Livewire::actingAs($admin)->test('workflows.edit')->set('tracker_id', $tracker->id)->set('role_id', $role->id);
    expect($editor->get('transitions'))->toHaveKey("0-{$s['new']->id}");
    $editor->assertSee('新規課題', false);

    $editor->set("transitions.0-{$s['draft']->id}", true)->call('save')->assertHasNoErrors();

    expect(WorkflowTransition::query()->where('tracker_id', $tracker->id)->where('role_id', $role->id)->whereNull('old_status_id')->pluck('new_status_id')->all())
        ->toEqualCanonicalizing([$s['new']->id, $s['draft']->id]);

    $editor->set("transitions.0-{$s['new']->id}", false)->set("transitions.0-{$s['draft']->id}", false)->call('save');
    expect(WorkflowTransition::query()->where('tracker_id', $tracker->id)->whereNull('old_status_id')->count())->toBe(0);
});

test('copying a workflow carries the new issue row along', function () {
    ['tracker' => $tracker, 'role' => $role, 'statuses' => $s] = newIssueWorkflow();
    newIssueRow($tracker, $role, $s['draft']);
    $targetTracker = Tracker::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())->test('workflows.edit')
        ->set('copySourceTrackerId', $tracker->id)->set('copySourceRoleId', $role->id)
        ->set('copyTargetTrackerIds', [$targetTracker->id])->set('copyTargetRoleIds', [$role->id])
        ->call('copyWorkflow');

    expect(WorkflowTransition::query()->where('tracker_id', $targetTracker->id)->whereNull('old_status_id')->pluck('new_status_id')->all())->toBe([$s['draft']->id]);
});
