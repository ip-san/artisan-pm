<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
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

test('deleting a parent issue orphans its children instead of deleting them', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $parent = deletableIssue($project);
    $child = deletableIssue($project);
    $child->update(['parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->call('deleteIssue');

    expect($child->fresh()->parent_id)->toBeNull();
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

test('a tampered todo value is rejected', function () {
    $project = Project::factory()->create();
    $user = deletionProjectMember($project, ['view_issues', 'delete_issues']);
    $issue = deletableIssue($project);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1]);

    expect(fn () => Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('timeEntryTodo', 'explode')
        ->call('deleteIssue'))->toThrow(ValueError::class);

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeTrue();
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
