<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
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

test('deleting a parent issue deletes its subtasks too (A1-34, formerly orphaned them)', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $parent = deletableIssue($project);
    $child = deletableIssue($project);
    $child->update(['parent_id' => $parent->id]);
    $grandchild = deletableIssue($project);
    $grandchild->update(['parent_id' => $child->id]);
    $unrelated = deletableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->call('deleteIssue');

    expect(Issue::query()->whereKey([$parent->id, $child->id, $grandchild->id])->exists())->toBeFalse()
        ->and($unrelated->fresh())->not->toBeNull();
});

test('the delete flow can delete the issue\'s time entries together with it', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 2]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('timeEntryTodo', 'destroy')
        ->call('deleteIssue');

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(Issue::query()->whereKey($issue->id)->exists())->toBeFalse();
});

test('the delete flow can move the time entries to another issue of the project', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    $target = deletableIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 2]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('timeEntryTodo', 'reassign')
        ->set('reassignToId', (string) $target->id)
        ->call('deleteIssue');

    expect($entry->fresh()->issue_id)->toBe($target->id)
        ->and(Issue::query()->whereKey($issue->id)->exists())->toBeFalse();
});

test('reassigning to an issue of another project or to the issue itself is rejected and nothing is deleted', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    $foreign = deletableIssue(Project::factory()->create());
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 2]);

    foreach ([(string) $foreign->id, (string) $issue->id, '99999', ''] as $badTarget) {
        Livewire::actingAs($user)
            ->test('issues.show', ['project' => $project, 'issue' => $issue])
            ->set('timeEntryTodo', 'reassign')
            ->set('reassignToId', $badTarget)
            ->call('deleteIssue')
            ->assertHasErrors('reassign_to_id');
    }

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue()
        ->and($entry->fresh()->issue_id)->toBe($issue->id);
});

test('an issue without logged time is deleted straight away whatever the stale form state says', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('timeEntryTodo', 'reassign')
        ->call('deleteIssue')
        ->assertHasNoErrors();

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeFalse();
});

test('a tampered todo value is rejected with an error and nothing is deleted', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('timeEntryTodo', 'explode')
        ->call('deleteIssue')
        ->assertHasErrors('timeEntryTodo')
        ->assertNoRedirect();

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue()
        ->and($entry->fresh()->issue_id)->toBe($issue->id);
});

test('the delete confirmation panel appears only when the issue has logged time', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $withTime = deletableIssue($project);
    $without = deletableIssue($project);
    TimeEntry::factory()->for($project)->create(['issue_id' => $withTime->id, 'hours' => 1.5]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $withTime])
        ->assertDontSee('作業時間をどうしますか')
        ->call('$set', 'confirmingDelete', true)
        ->assertSee('1.5 時間の作業時間が記録されています');

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $without])
        ->call('$set', 'confirmingDelete', true)
        ->assertDontSee('作業時間をどうしますか');
});

/**
 * @return array{parent: Issue, child: Issue, grandchild: Issue}
 */
function deletableIssueTree(Project $project): array
{
    $parent = deletableIssue($project);
    $child = deletableIssue($project);
    $child->update(['parent_id' => $parent->id]);
    $grandchild = deletableIssue($project);
    $grandchild->update(['parent_id' => $child->id]);

    return ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild];
}

test('the subtasks\' logged time counts in the delete confirmation and is detached by default', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    ['parent' => $parent, 'grandchild' => $grandchild] = deletableIssueTree($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $grandchild->id, 'hours' => 2.5]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->call('$set', 'confirmingDelete', true)
        ->assertSee('2件のサブタスクも削除されます。')
        ->assertSee('この課題とサブタスクには 2.5 時間の作業時間が記録されています')
        ->call('deleteIssue')
        ->assertHasNoErrors();

    expect($entry->fresh()->issue_id)->toBeNull()
        ->and(Issue::query()->whereKey($grandchild->id)->exists())->toBeFalse();
});

test('the subtasks\' logged time can be deleted with them', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    ['parent' => $parent, 'child' => $child] = deletableIssueTree($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $child->id, 'hours' => 1]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->set('timeEntryTodo', 'destroy')
        ->call('deleteIssue');

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('the subtasks\' logged time can be reassigned, but never to a subtask being deleted', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = deletableIssueTree($project);
    $target = deletableIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $grandchild->id, 'hours' => 1]);

    foreach ([$child->id, $grandchild->id] as $doomedTarget) {
        Livewire::actingAs($user)
            ->test('issues.show', ['project' => $project, 'issue' => $parent])
            ->set('timeEntryTodo', 'reassign')
            ->set('reassignToId', (string) $doomedTarget)
            ->call('deleteIssue')
            ->assertHasErrors('reassign_to_id');
    }

    expect(Issue::query()->whereKey([$parent->id, $child->id, $grandchild->id])->count())->toBe(3);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->set('timeEntryTodo', 'reassign')
        ->set('reassignToId', (string) $target->id)
        ->call('deleteIssue')
        ->assertHasNoErrors();

    expect($entry->fresh()->issue_id)->toBe($target->id);
});

test('the plain delete confirm mentions the subtasks only when there are some', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    ['parent' => $parent] = deletableIssueTree($project);
    $leaf = deletableIssue($project);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSee('2件のサブタスクも削除されます。');
    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $leaf])
        ->assertDontSee('サブタスクも削除されます');
});

test('like Redmine, subtasks the user cannot see or delete are deleted with the parent', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->private()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'delete_issues'], 'issues_visibility' => 'default'])
    );
    $parent = deletableIssue($project);
    $privateChild = deletableIssue($project);
    $privateChild->update(['parent_id' => $parent->id, 'is_private' => true]);
    $foreignChild = deletableIssue($otherProject);
    $foreignChild->update(['parent_id' => $parent->id]);

    expect($privateChild->fresh()->isVisibleTo($user))->toBeFalse()
        ->and($foreignChild->fresh()->isVisibleTo($user))->toBeFalse();

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->call('deleteIssue');

    expect(Issue::query()->whereKey([$parent->id, $privateChild->id, $foreignChild->id])->exists())->toBeFalse();
});

test('deleting a subtree recalculates the surviving parent', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $parent = deletableIssue($project);
    $early = deletableIssue($project);
    $early->update(['parent_id' => $parent->id, 'start_date' => '2026-01-01', 'due_date' => '2026-01-05']);
    $late = deletableIssue($project);
    $late->update(['parent_id' => $parent->id, 'start_date' => '2026-03-01', 'due_date' => '2026-03-10']);
    $parent->forceFill(['start_date' => '2026-01-01', 'due_date' => '2026-03-10'])->save();

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $early])
        ->call('deleteIssue');

    expect($parent->fresh()->start_date->toDateString())->toBe('2026-03-01');
});

test('the REST API delete removes the subtasks too', function () {
    Setting::set('rest_api_enabled', true);
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = deletableIssueTree($project);
    $this->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])
        ->deleteJson(route('api.issues.destroy', $parent))
        ->assertNoContent();

    expect(Issue::query()->whereKey([$parent->id, $child->id, $grandchild->id])->exists())->toBeFalse();
});
