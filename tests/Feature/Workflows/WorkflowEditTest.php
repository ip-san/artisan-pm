<?php

use App\Enums\WorkflowFieldRuleType;
use App\Models\CustomField;
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
use Livewire\Livewire;

test('a non-admin cannot access the workflow editor', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('workflows.edit')->assertForbidden();
});

test('an admin can save status transitions for a tracker and role', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $new = IssueStatus::factory()->create(['name' => 'New']);
    $inProgress = IssueStatus::factory()->create(['name' => 'In Progress']);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id)
        ->set("transitions.{$new->id}-{$inProgress->id}", true)
        ->call('save');

    $transition = WorkflowTransition::query()
        ->where('tracker_id', $tracker->id)
        ->where('role_id', $role->id)
        ->where('old_status_id', $new->id)
        ->where('new_status_id', $inProgress->id)
        ->first();

    expect($transition)->not->toBeNull()
        ->and($transition->author)->toBeFalse()
        ->and($transition->assignee)->toBeFalse();
});

test('unchecking a transition on save removes it', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $new = IssueStatus::factory()->create();
    $inProgress = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $new->id, 'new_status_id' => $inProgress->id,
        'author' => false, 'assignee' => false,
    ]);

    $component = Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id);

    expect($component->get('transitions'))->toHaveKey("{$new->id}-{$inProgress->id}");

    $component->set("transitions.{$new->id}-{$inProgress->id}", false)->call('save');

    expect(WorkflowTransition::where('tracker_id', $tracker->id)->where('role_id', $role->id)->count())->toBe(0);
});

test('saving the author context does not affect the general context', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $new = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $new->id, 'new_status_id' => $closed->id,
        'author' => false, 'assignee' => false,
    ]);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id)
        ->set('context', 'author')
        ->set("transitions.{$new->id}-{$closed->id}", true)
        ->call('save');

    expect(WorkflowTransition::where('tracker_id', $tracker->id)->where('role_id', $role->id)->where('author', false)->count())->toBe(1)
        ->and(WorkflowTransition::where('tracker_id', $tracker->id)->where('role_id', $role->id)->where('author', true)->count())->toBe(1);
});

test('an admin can save field rules', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id)
        ->set("fieldRules.subject-{$status->id}", WorkflowFieldRuleType::Required->value)
        ->call('save');

    $rule = WorkflowFieldRule::query()
        ->where('tracker_id', $tracker->id)->where('role_id', $role->id)
        ->where('field_name', 'subject')->where('status_id', $status->id)
        ->first();

    expect($rule)->not->toBeNull()
        ->and($rule->rule)->toBe(WorkflowFieldRuleType::Required);
});

test('a tracker custom field appears as a field-rule row once selected', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);

    $component = Livewire::actingAs($admin)->test('workflows.edit')->set('tracker_id', $tracker->id);

    expect($component->get('fields'))->toHaveKey("cf_{$field->id}");
});

test('the saved matrix is actually consumed by WorkflowService', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $new = IssueStatus::factory()->create(['name' => 'New']);
    $inProgress = IssueStatus::factory()->create(['name' => 'In Progress']);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id)
        ->set("transitions.{$new->id}-{$inProgress->id}", true)
        ->set("fieldRules.subject-{$new->id}", WorkflowFieldRuleType::ReadOnly->value)
        ->call('save');

    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $new->id]);

    $allowed = app(WorkflowService::class)->allowedTransitions($issue, $user);
    $rules = app(WorkflowService::class)->fieldRules($issue, $user);

    expect($allowed->pluck('id'))->toContain($inProgress->id)
        ->and($rules['subject'] ?? null)->toBe(WorkflowFieldRuleType::ReadOnly->value);
});

test('the status grid is limited to statuses used in the tracker\'s existing transitions by default', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $used = IssueStatus::factory()->create(['name' => 'Used']);
    $alsoUsed = IssueStatus::factory()->create(['name' => 'Also Used']);
    $unused = IssueStatus::factory()->create(['name' => 'Unused']);

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $used->id, 'new_status_id' => $alsoUsed->id,
        'author' => false, 'assignee' => false,
    ]);

    $component = Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id);

    $statusIds = $component->get('statuses')->pluck('id');

    expect($statusIds)->toContain($used->id)
        ->toContain($alsoUsed->id)
        ->not->toContain($unused->id);
});

test('unchecking used-statuses-only shows every status', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $used = IssueStatus::factory()->create();
    $alsoUsed = IssueStatus::factory()->create();
    $unused = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $used->id, 'new_status_id' => $alsoUsed->id,
        'author' => false, 'assignee' => false,
    ]);

    $component = Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id)
        ->set('usedStatusesOnly', false);

    expect($component->get('statuses')->pluck('id'))->toContain($unused->id);
});

test('a tracker with no existing transitions falls back to showing every status', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $status = IssueStatus::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('tracker_id', $tracker->id)
        ->set('role_id', $role->id);

    expect($component->get('statuses')->pluck('id'))->toContain($status->id);
});

test('copying a workflow duplicates transitions and field rules onto every target tracker/role combination', function () {
    $admin = User::factory()->admin()->create();
    $sourceTracker = Tracker::factory()->create();
    $sourceRole = Role::factory()->create();
    $targetTrackerA = Tracker::factory()->create();
    $targetTrackerB = Tracker::factory()->create();
    $targetRole = Role::factory()->create();
    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $sourceTracker->id, 'role_id' => $sourceRole->id,
        'old_status_id' => $statusA->id, 'new_status_id' => $statusB->id,
        'author' => false, 'assignee' => false,
    ]);
    WorkflowFieldRule::create([
        'tracker_id' => $sourceTracker->id, 'role_id' => $sourceRole->id,
        'status_id' => $statusA->id, 'field_name' => 'subject',
        'rule' => WorkflowFieldRuleType::Required, 'author' => false, 'assignee' => false,
    ]);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('copySourceTrackerId', $sourceTracker->id)
        ->set('copySourceRoleId', $sourceRole->id)
        ->set('copyTargetTrackerIds', [$targetTrackerA->id, $targetTrackerB->id])
        ->set('copyTargetRoleIds', [$targetRole->id])
        ->call('copyWorkflow')
        ->assertHasNoErrors();

    foreach ([$targetTrackerA, $targetTrackerB] as $targetTracker) {
        $transition = WorkflowTransition::where('tracker_id', $targetTracker->id)->where('role_id', $targetRole->id)->sole();
        expect($transition->old_status_id)->toBe($statusA->id)
            ->and($transition->new_status_id)->toBe($statusB->id);

        $fieldRule = WorkflowFieldRule::where('tracker_id', $targetTracker->id)->where('role_id', $targetRole->id)->sole();
        expect($fieldRule->field_name)->toBe('subject')
            ->and($fieldRule->rule)->toBe(WorkflowFieldRuleType::Required);
    }
});

test('copying a workflow replaces (not merges into) the target pair\'s existing rules', function () {
    $admin = User::factory()->admin()->create();
    $sourceTracker = Tracker::factory()->create();
    $sourceRole = Role::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $targetRole = Role::factory()->create();
    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();
    $staleStatus = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $targetTracker->id, 'role_id' => $targetRole->id,
        'old_status_id' => $staleStatus->id, 'new_status_id' => $statusB->id,
        'author' => false, 'assignee' => false,
    ]);
    WorkflowTransition::create([
        'tracker_id' => $sourceTracker->id, 'role_id' => $sourceRole->id,
        'old_status_id' => $statusA->id, 'new_status_id' => $statusB->id,
        'author' => false, 'assignee' => false,
    ]);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('copySourceTrackerId', $sourceTracker->id)
        ->set('copySourceRoleId', $sourceRole->id)
        ->set('copyTargetTrackerIds', [$targetTracker->id])
        ->set('copyTargetRoleIds', [$targetRole->id])
        ->call('copyWorkflow');

    $remaining = WorkflowTransition::where('tracker_id', $targetTracker->id)->where('role_id', $targetRole->id)->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->old_status_id)->toBe($statusA->id);
});

test('the source tracker/role pair is skipped when it also appears among the targets', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();
    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();

    WorkflowTransition::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id,
        'old_status_id' => $statusA->id, 'new_status_id' => $statusB->id,
        'author' => false, 'assignee' => false,
    ]);

    Livewire::actingAs($admin)
        ->test('workflows.edit')
        ->set('copySourceTrackerId', $tracker->id)
        ->set('copySourceRoleId', $role->id)
        ->set('copyTargetTrackerIds', [$tracker->id])
        ->set('copyTargetRoleIds', [$role->id])
        ->call('copyWorkflow')
        ->assertHasNoErrors();

    expect(WorkflowTransition::where('tracker_id', $tracker->id)->where('role_id', $role->id)->count())->toBe(1);
});

test('a non-admin cannot access the workflow editor to copy a workflow', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('workflows.edit')->assertForbidden();
});
