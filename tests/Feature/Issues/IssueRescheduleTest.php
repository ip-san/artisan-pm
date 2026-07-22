<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Livewire\Livewire;

function rescheduleProjectMember(Project $project, array $permissions = ['view_issues', 'edit_issues', 'manage_issue_relations']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function rescheduleIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([...[
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ], ...$attributes]);
}

test('editing a predecessor\'s due date pushes a precedes successor to start the next day', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['start_date' => '2026-01-01', 'due_date' => '2026-01-05']);
    $successor = rescheduleIssue($project, ['start_date' => '2026-01-02', 'due_date' => '2026-01-03']);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-01-10'], $user);

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-01-11')
        ->and($successor->fresh()->due_date->toDateString())->toBe('2026-01-12');
});

test('the relation delay is added on top of the day after the predecessor\'s due date', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['due_date' => '2026-02-01']);
    $successor = rescheduleIssue($project);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes', 'delay' => 3]);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-02-10'], $user);

    // 2026-02-10 + 1 (next day) + 3 (delay) = 2026-02-14
    expect($successor->fresh()->start_date->toDateString())->toBe('2026-02-14');
});

test('a follows relation reschedules from the other issue as predecessor', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['due_date' => '2026-03-01']);
    $successor = rescheduleIssue($project);
    // successor "follows" predecessor: issue_from is the successor, issue_to is the predecessor.
    IssueRelation::create(['issue_from_id' => $successor->id, 'issue_to_id' => $predecessor->id, 'relation_type' => 'follows']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-03-10'], $user);

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-03-11');
});

test('a successor already starting on or after the soonest start is left untouched', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['due_date' => '2026-04-01']);
    $successor = rescheduleIssue($project, ['start_date' => '2026-05-01', 'due_date' => '2026-05-05']);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-04-10'], $user);

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-05-01')
        ->and($successor->fresh()->due_date->toDateString())->toBe('2026-05-05');
});

test('a reschedule chain cascades through multiple precedes relations', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $first = rescheduleIssue($project, ['due_date' => '2026-06-01']);
    $second = rescheduleIssue($project, ['start_date' => '2026-06-02', 'due_date' => '2026-06-03']);
    $third = rescheduleIssue($project, ['start_date' => '2026-06-04', 'due_date' => '2026-06-05']);
    IssueRelation::create(['issue_from_id' => $first->id, 'issue_to_id' => $second->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $second->id, 'issue_to_id' => $third->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($first, ['due_date' => '2026-06-20'], $user);

    expect($second->fresh()->start_date->toDateString())->toBe('2026-06-21')
        ->and($second->fresh()->due_date->toDateString())->toBe('2026-06-22')
        ->and($third->fresh()->start_date->toDateString())->toBe('2026-06-23');
});

test('a reschedule cascade that loops back on itself does not recurse forever', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $a = rescheduleIssue($project, ['start_date' => '2026-07-01', 'due_date' => '2026-07-02']);
    $b = rescheduleIssue($project, ['start_date' => '2026-07-03', 'due_date' => '2026-07-04']);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);
    // Not blocked by relation-creation validation (only direct blocks/relates
    // cycles are checked), so this deliberately builds a 2-node precedes loop.
    IssueRelation::create(['issue_from_id' => $b->id, 'issue_to_id' => $a->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($a, ['due_date' => '2026-07-10'], $user);

    // Reaching this line without a timeout/stack overflow is the point of
    // the test; the exact resulting dates aren't asserted since the cycle
    // guard's cutoff is an implementation detail.
    expect(true)->toBeTrue();
});

test('creating a precedes relation immediately reschedules the successor from the predecessor\'s current dates', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['due_date' => '2026-08-01']);
    $successor = rescheduleIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $predecessor])
        ->set('relationType', 'precedes')
        ->set('relatedIssueId', $successor->id)
        ->call('addRelation')
        ->assertHasNoErrors();

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-08-02');
});

test('a relates relation does not trigger a reschedule', function () {
    $project = Project::factory()->create();
    $user = rescheduleProjectMember($project);
    $predecessor = rescheduleIssue($project, ['due_date' => '2026-09-01']);
    $successor = rescheduleIssue($project);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'relates']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-09-10'], $user);

    expect($successor->fresh()->start_date)->toBeNull();
});
