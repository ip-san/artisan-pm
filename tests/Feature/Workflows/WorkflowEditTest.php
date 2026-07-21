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
