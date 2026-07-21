<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowFieldRule;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;

function workflowService(): WorkflowService
{
    return app(WorkflowService::class);
}

function memberWithRole(Project $project, Role $role): User
{
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('admins may transition an issue to any status', function () {
    $admin = User::factory()->admin()->create();
    $issue = Issue::factory()->for($project = Project::factory()->create())->create();

    IssueStatus::factory()->count(3)->create();

    expect(workflowService()->allowedTransitions($issue, $admin)->count())
        ->toBe(IssueStatus::count());
});

test('a user only sees transitions their role is granted for the current status', function () {
    $tracker = Tracker::factory()->create();
    $new = IssueStatus::factory()->create(['name' => 'New']);
    $inProgress = IssueStatus::factory()->create(['name' => 'In Progress']);
    $closed = IssueStatus::factory()->closed()->create(['name' => 'Closed']);

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = memberWithRole($project, $role);

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $new->id, 'new_status_id' => $inProgress->id,
    ]);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $new->id]);

    $allowed = workflowService()->allowedTransitions($issue, $user);

    expect($allowed->pluck('id')->all())->toBe([$inProgress->id])
        ->and($allowed->pluck('id'))->not->toContain($closed->id);
});

test('a transition restricted to the author is only available to the issue author', function () {
    $tracker = Tracker::factory()->create();
    $new = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $author = memberWithRole($project, $role);
    $otherMember = memberWithRole($project, $role);

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $new->id, 'new_status_id' => $closed->id, 'author' => true,
    ]);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $new->id, 'author_id' => $author->id,
    ]);

    expect(workflowService()->allowedTransitions($issue, $author)->pluck('id')->all())->toBe([$closed->id])
        ->and(workflowService()->allowedTransitions($issue, $otherMember)->pluck('id')->all())->toBe([]);
});

test('field rules combine required-over-read_only across multiple roles', function () {
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $roleA = Role::factory()->create(['permissions' => ['view_issues']]);
    $roleB = Role::factory()->create(['permissions' => ['view_issues']]);

    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach([$roleA->id, $roleB->id]);

    WorkflowFieldRule::create([
        'tracker_id' => $tracker->id, 'role_id' => $roleA->id, 'status_id' => $status->id,
        'field_name' => 'due_date', 'rule' => 'read_only',
    ]);
    WorkflowFieldRule::create([
        'tracker_id' => $tracker->id, 'role_id' => $roleB->id, 'status_id' => $status->id,
        'field_name' => 'due_date', 'rule' => 'required',
    ]);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    expect(workflowService()->fieldRules($issue, $user))->toBe(['due_date' => 'required']);
});

test('a user with no role in the project has no allowed transitions or field rules', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    expect(workflowService()->allowedTransitions($issue, $user))->toBeEmpty()
        ->and(workflowService()->fieldRules($issue, $user))->toBe([]);
});
