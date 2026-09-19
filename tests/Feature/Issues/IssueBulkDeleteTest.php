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
    App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $withTime->id, 'hours' => 2.5]);

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
    $entry = App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkTimeEntryTodo', 'destroy')
        ->call('applyBulkDelete')
        ->assertHasNoErrors();

    expect(App\Models\TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('bulk deleting keeps the time detached by default', function () {
    $project = Project::factory()->create();
    $user = bulkDeleteMember($project, ['view_issues', 'delete_issues']);
    $issue = bulkDeleteIssue($project);
    $entry = App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

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
    $entryA = App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $a->id, 'hours' => 1]);
    $entryB = App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $b->id, 'hours' => 2]);

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
    App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $a->id, 'hours' => 1]);

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
    App\Models\TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->set('bulkTimeEntryTodo', 'explode')
        ->call('applyBulkDelete')
        ->assertHasErrors('bulkTimeEntryTodo');

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue();
});
