<?php

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueRelation;
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

/**
 * @param  array<int, Role>  $roles
 */
function workflowServiceRuleUser(Project $project, array $roles): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(collect($roles)->pluck('id')->all());

    return $user;
}

function workflowServiceFieldRule(Tracker $tracker, IssueStatus $status, Role $role, string $field, string $rule): void
{
    WorkflowFieldRule::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id, 'status_id' => $status->id,
        'field_name' => $field, 'rule' => $rule,
    ]);
}

test('field rules that disagree across roles make the field required', function () {
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project = Project::factory()->create();
    $roleA = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $roleB = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = workflowServiceRuleUser($project, [$roleA, $roleB]);

    workflowServiceFieldRule($tracker, $status, $roleA, 'due_date', 'read_only');
    workflowServiceFieldRule($tracker, $status, $roleB, 'due_date', 'required');
    workflowServiceFieldRule($tracker, $status, $roleA, 'start_date', 'read_only');
    workflowServiceFieldRule($tracker, $status, $roleB, 'start_date', 'read_only');

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    expect(workflowService()->fieldRules($issue, $user))->toBe(['due_date' => 'required', 'start_date' => 'read_only']);
});

test('a field rule applies only when every workflow role of the user has one (Redmine)', function () {
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project = Project::factory()->create();
    $restricted = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $free = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $viewer = Role::factory()->create(['permissions' => ['view_issues']]);

    workflowServiceFieldRule($tracker, $status, $restricted, 'due_date', 'read_only');
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    // The second role has no rule for the field, so it stays writable.
    expect(workflowService()->fieldRules($issue, workflowServiceRuleUser($project, [$restricted, $free])))->toBe([])
        // A role that can neither add nor edit issues is not part of the workflow.
        ->and(workflowService()->fieldRules($issue, workflowServiceRuleUser($project, [$restricted, $viewer])))->toBe(['due_date' => 'read_only']);
});

test('administrators get the rules every add/edit role shares (Redmine roles_for_workflow)', function () {
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project = Project::factory()->create();
    Role::query()->get()->each(fn (Role $role) => $role->update(['permissions' => ['view_issues']]));
    $roleA = Role::factory()->create(['permissions' => ['edit_issues']]);
    $roleB = Role::factory()->create(['permissions' => ['add_issues']]);
    Role::factory()->create(['permissions' => ['view_issues']]);
    $admin = User::factory()->admin()->create();

    workflowServiceFieldRule($tracker, $status, $roleA, 'due_date', 'read_only');
    workflowServiceFieldRule($tracker, $status, $roleB, 'due_date', 'read_only');
    workflowServiceFieldRule($tracker, $status, $roleA, 'start_date', 'required');

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    expect(workflowService()->fieldRules($issue, $admin))->toBe(['due_date' => 'read_only']);
});

test('a custom field limited to other roles counts as read-only for the roles that cannot see it', function () {
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project = Project::factory()->create();
    $seer = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $blind = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $field = CustomField::factory()->create(['customized_type' => 'issue']);
    $field->roles()->attach($seer);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    // No workflow rule at all: Redmine skips the custom field step too.
    expect(workflowService()->fieldRules($issue, workflowServiceRuleUser($project, [$blind])))->toBe([]);

    workflowServiceFieldRule($tracker, $status, $blind, 'due_date', 'read_only');
    workflowServiceFieldRule($tracker, $status, $seer, "cf_{$field->id}", 'required');

    expect(workflowService()->fieldRules($issue, workflowServiceRuleUser($project, [$blind])))->toBe(['due_date' => 'read_only', "cf_{$field->id}" => 'read_only'])
        ->and(workflowService()->fieldRules($issue, workflowServiceRuleUser($project, [$seer, $blind])))->toBe(["cf_{$field->id}" => 'required']);
});

test('a user with no role in the project has no allowed transitions or field rules', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    expect(workflowService()->allowedTransitions($issue, $user))->toBeEmpty()
        ->and(workflowService()->fieldRules($issue, $user))->toBe([]);
});

test('an issue blocked by an open issue cannot transition to a closed status, even for admins', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create(['status_id' => $open->id]);
    $blocker = Issue::factory()->for($project)->create(['status_id' => $open->id]);
    IssueRelation::create([
        'issue_from_id' => $blocker->id,
        'issue_to_id' => $issue->id,
        'relation_type' => 'blocks',
    ]);

    $allowed = workflowService()->allowedTransitions($issue, $admin);

    expect($allowed->pluck('id'))->not->toContain($closed->id)
        ->and($issue->isBlocked())->toBeTrue()
        ->and($issue->isClosable())->toBeFalse();
});

test('once the blocking issue is closed, the blocked issue can transition to a closed status again', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create(['status_id' => $open->id]);
    $blocker = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    IssueRelation::create([
        'issue_from_id' => $blocker->id,
        'issue_to_id' => $issue->id,
        'relation_type' => 'blocks',
    ]);

    expect($issue->isBlocked())->toBeFalse()
        ->and(workflowService()->allowedTransitions($issue, $admin)->pluck('id'))->toContain($closed->id);
});

test('an issue with an open subtask cannot transition to a closed status', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $parent = Issue::factory()->for($project)->create(['status_id' => $open->id]);
    Issue::factory()->for($project)->create(['status_id' => $open->id, 'parent_id' => $parent->id]);

    expect($parent->hasOpenChildren())->toBeTrue()
        ->and(workflowService()->allowedTransitions($parent, $admin)->pluck('id'))->not->toContain($closed->id);
});

test('the current status always stays selectable even when the issue is blocked', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    $blocker = Issue::factory()->for($project)->create(['status_id' => IssueStatus::factory()->create()->id]);
    IssueRelation::create([
        'issue_from_id' => $blocker->id,
        'issue_to_id' => $issue->id,
        'relation_type' => 'blocks',
    ]);

    expect(workflowService()->allowedTransitions($issue, $admin)->pluck('id'))->toContain($closed->id);
});

test('a subtask of a closed parent cannot transition to an open status', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $anotherOpen = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $parent = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    $child = Issue::factory()->for($project)->create(['status_id' => $closed->id, 'parent_id' => $parent->id]);

    expect($child->isReopenable())->toBeFalse()
        ->and(workflowService()->allowedTransitions($child, $admin)->pluck('id'))
        ->not->toContain($open->id)
        ->not->toContain($anotherOpen->id);
});

test('a subtask of a closed grandparent cannot transition to an open status either', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $grandparent = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    $parent = Issue::factory()->for($project)->create(['status_id' => $open->id, 'parent_id' => $grandparent->id]);
    $child = Issue::factory()->for($project)->create(['status_id' => $closed->id, 'parent_id' => $parent->id]);

    expect($child->isReopenable())->toBeFalse()
        ->and(workflowService()->allowedTransitions($child, $admin)->pluck('id'))->not->toContain($open->id);
});

test('once the closed parent reopens, its subtask can transition to an open status again', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $parent = Issue::factory()->for($project)->create(['status_id' => $open->id]);
    $child = Issue::factory()->for($project)->create(['status_id' => $closed->id, 'parent_id' => $parent->id]);

    expect($child->isReopenable())->toBeTrue()
        ->and(workflowService()->allowedTransitions($child, $admin)->pluck('id'))->toContain($open->id);
});

test('an issue with no parent is always reopenable', function () {
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create(['status_id' => $closed->id]);

    expect($issue->isReopenable())->toBeTrue();
});

test('the current status always stays selectable even when the issue is not reopenable', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $anotherClosed = IssueStatus::factory()->closed()->create();
    $parent = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    $child = Issue::factory()->for($project)->create(['status_id' => $anotherClosed->id, 'parent_id' => $parent->id]);

    expect(workflowService()->allowedTransitions($child, $admin)->pluck('id'))->toContain($anotherClosed->id);
});
