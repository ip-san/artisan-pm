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

function bulkDeleteMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function bulkDeleteIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a user with delete_issues can bulk delete selected issues', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $issueA = bulkDeleteIssue($project);
    $issueB = bulkDeleteIssue($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issueA->id, $issueB->id])
        ->call('applyBulkDelete');

    expect(Issue::query()->whereKey($issueA->id)->exists())->toBeFalse()
        ->and(Issue::query()->whereKey($issueB->id)->exists())->toBeFalse();
});

test('a user without delete_issues cannot bulk delete issues', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues']);
    $issue = bulkDeleteIssue($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->call('applyBulkDelete')
        ->assertForbidden();

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue();
});

test('the bulk delete button is not shown without delete_issues', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues']);
    $issue = bulkDeleteIssue($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->assertDontSee('を削除');
});

test('the bulk delete asks about time only when the selection has logged hours', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $withTime = bulkDeleteIssue($project);
    $without = bulkDeleteIssue($project);
    TimeEntry::factory()->for($project)->create(['issue_id' => $withTime->id, 'hours' => 2.5]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$without->id])
        ->assertSet('confirmingBulkDelete', false)
        ->assertDontSee('作業時間をどうしますか')
        ->set('selected', [$withTime->id, $without->id])
        ->call('$set', 'confirmingBulkDelete', true)
        ->assertSee('合計 2.5 時間の作業時間が記録されています');
});

test('bulk deleting can delete the logged time with the issues', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $issue = bulkDeleteIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkTimeEntryTodo', 'destroy')
        ->call('applyBulkDelete')
        ->assertHasNoErrors();

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('bulk deleting keeps the time detached by default', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $issue = bulkDeleteIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->call('applyBulkDelete');

    expect($entry->fresh()->issue_id)->toBeNull();
});

test('bulk deleting can move all the logged time to a surviving issue', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $a = bulkDeleteIssue($project);
    $b = bulkDeleteIssue($project);
    $survivor = bulkDeleteIssue($project);
    $entryA = TimeEntry::factory()->for($project)->create(['issue_id' => $a->id, 'hours' => 1]);
    $entryB = TimeEntry::factory()->for($project)->create(['issue_id' => $b->id, 'hours' => 2]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$a->id, $b->id])
        ->set('bulkTimeEntryTodo', 'reassign')
        ->set('bulkReassignToId', (string) $survivor->id)
        ->call('applyBulkDelete')
        ->assertHasNoErrors();

    expect($entryA->fresh()->issue_id)->toBe($survivor->id)
        ->and($entryB->fresh()->issue_id)->toBe($survivor->id)
        ->and(Issue::query()->whereKey($a->id)->exists())->toBeFalse();
});

test('a reassign target that is itself selected, missing, or in another project is refused before anything is deleted', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $a = bulkDeleteIssue($project);
    $b = bulkDeleteIssue($project);
    $foreign = bulkDeleteIssue(Project::factory()->create());
    TimeEntry::factory()->for($project)->create(['issue_id' => $a->id, 'hours' => 1]);

    foreach ([(string) $b->id, '', (string) $foreign->id, '999999'] as $target) {
        Livewire::actingAs($user)->test('issues.index', ['project' => $project])
            ->set('selected', [$a->id, $b->id])
            ->set('bulkTimeEntryTodo', 'reassign')
            ->set('bulkReassignToId', $target)
            ->call('applyBulkDelete')
            ->assertHasErrors('bulkReassignToId');
    }

    expect(Issue::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(2);
});

test('a tampered bulk todo value is rejected and nothing is deleted', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $issue = bulkDeleteIssue($project);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkTimeEntryTodo', 'explode')
        ->call('applyBulkDelete')
        ->assertHasErrors('bulkTimeEntryTodo');

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue();
});

test('bulk deleting a selection holding both a parent and its child deletes the whole subtree once (A1-34)', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $parent = bulkDeleteIssue($project);
    $child = bulkDeleteIssue($project);
    $child->update(['parent_id' => $parent->id]);
    $grandchild = bulkDeleteIssue($project);
    $grandchild->update(['parent_id' => $child->id]);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $grandchild->id, 'hours' => 3]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$child->id, $parent->id])
        ->assertSet('bulkDeleteHours', 3.0)
        ->set('confirmingBulkDelete', true)
        ->assertSee('1件のサブタスクも削除されます。')
        ->assertSee('選択した課題とサブタスクには合計 3 時間')
        ->set('bulkTimeEntryTodo', 'destroy')
        ->call('applyBulkDelete')
        ->assertHasNoErrors();

    expect(Issue::query()->whereKey([$parent->id, $child->id, $grandchild->id])->exists())->toBeFalse()
        ->and(TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('a bulk reassign target that is a subtask of a selected issue is refused', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $parent = bulkDeleteIssue($project);
    $child = bulkDeleteIssue($project);
    $child->update(['parent_id' => $parent->id]);
    $survivor = bulkDeleteIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $parent->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$parent->id])
        ->set('bulkTimeEntryTodo', 'reassign')
        ->set('bulkReassignToId', (string) $child->id)
        ->call('applyBulkDelete')
        ->assertHasErrors('bulkReassignToId');

    expect(Issue::query()->whereKey([$parent->id, $child->id])->count())->toBe(2);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$parent->id])
        ->set('bulkTimeEntryTodo', 'reassign')
        ->set('bulkReassignToId', (string) $survivor->id)
        ->call('applyBulkDelete')
        ->assertHasNoErrors();

    expect($entry->fresh()->issue_id)->toBe($survivor->id)
        ->and(Issue::query()->whereKey($child->id)->exists())->toBeFalse();
});

test('the plain bulk delete confirm mentions the subtasks of the selection', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $parent = bulkDeleteIssue($project);
    $child = bulkDeleteIssue($project);
    $child->update(['parent_id' => $parent->id]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$parent->id])
        ->assertSee('選択した1件の課題を削除します。この操作は取り消せません。よろしいですか? 1件のサブタスクも削除されます。');
});

test('when time entries require an issue, the bulk delete and context menu refuse detaching and default to deleting the time (A1-40)', function () {
    Setting::set('timelog_required_fields', ['issue_id']);
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $a = bulkDeleteIssue($project);
    $b = bulkDeleteIssue($project);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $a->id, 'hours' => 1]);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->assertSet('bulkTimeEntryTodo', 'destroy')
        ->set('selected', [$a->id, $b->id])
        ->call('$set', 'confirmingBulkDelete', true)
        ->assertDontSee('課題との紐付けを外してプロジェクトに残す')
        ->set('bulkTimeEntryTodo', 'nullify')
        ->call('applyBulkDelete')
        ->assertHasErrors('bulkTimeEntryTodo');

    expect(Issue::query()->whereKey([$a->id, $b->id])->count())->toBe(2)
        ->and($entry->fresh()->issue_id)->toBe($a->id);

    $component->set('bulkTimeEntryTodo', 'destroy')
        ->call('applyBulkDelete');

    expect(Issue::query()->whereKey([$a->id, $b->id])->exists())->toBeFalse()
        ->and(TimeEntry::find($entry->id))->toBeNull();
});
