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
