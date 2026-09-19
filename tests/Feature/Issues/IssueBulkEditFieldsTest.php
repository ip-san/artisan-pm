<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowTransition;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 * @return array{project: Project, user: User, tracker: Tracker}
 */
function bulkFieldsSetup(array $permissions = ['view_project', 'view_issues', 'edit_issues', 'manage_subtasks', 'set_issues_private']): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return compact('project', 'user', 'tracker');
}

function bulkFieldsIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('bulk edit changes tracker, category and dates', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $other = Tracker::factory()->create();
    $project->trackers()->attach($other);
    $category = IssueCategory::factory()->for($project)->create();
    $a = bulkFieldsIssue($project, $tracker);
    $b = bulkFieldsIssue($project, $tracker);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$a->id, $b->id])
        ->set('bulkTrackerId', $other->id)
        ->set('bulkCategoryId', $category->id)
        ->set('bulkStartDate', '2026-05-01')
        ->set('bulkDueDate', '2026-05-31')
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    foreach ([$a, $b] as $issue) {
        $fresh = $issue->fresh();
        expect($fresh->tracker_id)->toBe($other->id)->and($fresh->category_id)->toBe($category->id)
            ->and($fresh->start_date->toDateString())->toBe('2026-05-01')->and($fresh->due_date->toDateString())->toBe('2026-05-31');
    }
});

test('a tracker or category from outside the project is rejected', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $foreignTracker = Tracker::factory()->create();
    $foreignCategory = IssueCategory::factory()->for(Project::factory()->create())->create();
    $issue = bulkFieldsIssue($project, $tracker);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id]);
    $list->set('bulkTrackerId', $foreignTracker->id)->call('applyBulkEdit')->assertHasErrors(['bulkTrackerId']);
    $list->set('bulkTrackerId', null)->set('bulkCategoryId', $foreignCategory->id)->call('applyBulkEdit')->assertHasErrors(['bulkCategoryId']);
    expect($issue->fresh()->tracker_id)->toBe($tracker->id);
});

test('bulk edit can flip the private flag for people who may', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $issue = bulkFieldsIssue($project, $tracker, ['is_private' => false]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id])->set('bulkIsPrivate', 'yes')->call('applyBulkEdit')->assertHasNoErrors();
    expect($issue->fresh()->is_private)->toBeTrue();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id])->set('bulkIsPrivate', 'no')->call('applyBulkEdit');
    expect($issue->fresh()->is_private)->toBeFalse();
});

test('without a private permission the flag cannot be changed in bulk', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup(['view_project', 'view_issues', 'edit_issues']);
    $issue = bulkFieldsIssue($project, $tracker);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id])->set('bulkIsPrivate', 'yes')->call('applyBulkEdit')->assertForbidden();
    expect($issue->fresh()->is_private)->toBeFalse();
});

test('a parent can be set in bulk but not to a selected issue or one of its descendants', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $parent = bulkFieldsIssue($project, $tracker);
    $a = bulkFieldsIssue($project, $tracker);
    $child = bulkFieldsIssue($project, $tracker, ['parent_id' => $a->id]);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$a->id]);
    $list->set('bulkParentId', $a->id)->call('applyBulkEdit')->assertHasErrors(['bulkParentId']);
    $list->set('bulkParentId', $child->id)->call('applyBulkEdit')->assertHasErrors(['bulkParentId']);
    $list->set('bulkParentId', $parent->id)->call('applyBulkEdit')->assertHasNoErrors();

    expect($a->fresh()->parent_id)->toBe($parent->id);
});

test('setting a parent in bulk needs manage_subtasks', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup(['view_project', 'view_issues', 'edit_issues']);
    $parent = bulkFieldsIssue($project, $tracker);
    $issue = bulkFieldsIssue($project, $tracker);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id])->set('bulkParentId', $parent->id)->call('applyBulkEdit')->assertForbidden();
    expect($issue->fresh()->parent_id)->toBeNull();
});

test('a parent from another project is refused', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $foreign = bulkFieldsIssue(Project::factory()->create(), $tracker);
    $issue = bulkFieldsIssue($project, $tracker);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$issue->id])->set('bulkParentId', $foreign->id)->call('applyBulkEdit')->assertHasErrors(['bulkParentId']);
});

test('a mixed selection offers only the transitions every issue shares', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $role = Role::query()->latest('id')->firstOrFail();
    $open = IssueStatus::factory()->create(['name' => 'Open', 'position' => 1]);
    $working = IssueStatus::factory()->create(['name' => 'Working', 'position' => 2]);
    $done = IssueStatus::factory()->create(['name' => 'Done', 'position' => 3]);
    $first = bulkFieldsIssue($project, $tracker, ['status_id' => $open->id]);
    $second = bulkFieldsIssue($project, $tracker, ['status_id' => $working->id]);
    foreach ([[$open, $working], [$open, $done], [$working, $done]] as [$from, $to]) {
        WorkflowTransition::create(['tracker_id' => $tracker->id, 'role_id' => $role->id, 'old_status_id' => $from->id, 'new_status_id' => $to->id]);
    }

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$first->id, $second->id]);

    expect($list->get('bulkStatusOptions')->pluck('name')->all())->toBe(['Done']);

    $list->set('bulkStatusId', $done->id)->call('applyBulkEdit')->assertHasNoErrors();
    expect($first->fresh()->status_id)->toBe($done->id)->and($second->fresh()->status_id)->toBe($done->id);
});

test('a status only some of the selected issues may reach is not offered or applied', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = bulkFieldsSetup();
    $role = Role::query()->latest('id')->firstOrFail();
    $open = IssueStatus::factory()->create(['name' => 'Open', 'position' => 1]);
    $working = IssueStatus::factory()->create(['name' => 'Working', 'position' => 2]);
    $first = bulkFieldsIssue($project, $tracker, ['status_id' => $open->id]);
    $second = bulkFieldsIssue($project, $tracker, ['status_id' => $working->id]);
    WorkflowTransition::create(['tracker_id' => $tracker->id, 'role_id' => $role->id, 'old_status_id' => $open->id, 'new_status_id' => $working->id]);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$first->id, $second->id]);

    expect($list->get('bulkStatusOptions'))->toHaveCount(0);
    $list->set('bulkStatusId', $working->id)->call('applyBulkEdit')->assertForbidden();
    expect($first->fresh()->status_id)->toBe($open->id);
});
