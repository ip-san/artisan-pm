<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

function totalsListMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'view_time_entries']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function totalsActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

test('the issue list shows estimated and spent hour totals for the filtered set', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $issueA = Issue::factory()->for($project)->create(['estimated_hours' => 4]);
    $issueB = Issue::factory()->for($project)->create(['estimated_hours' => 2.5]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $issueA->id, 'activity_id' => $activity->id, 'hours' => 3]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $issueB->id, 'activity_id' => $activity->id, 'hours' => 1.5]);

    $totals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->get('listTotals');

    expect($totals['estimated'])->toBe(6.5)
        ->and($totals['spent'])->toBe(4.5);
});

test('totals respect the active filters, not the whole project', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();
    $matching = Issue::factory()->for($project)->create(['status_id' => $statusA->id, 'estimated_hours' => 5]);
    $excluded = Issue::factory()->for($project)->create(['status_id' => $statusB->id, 'estimated_hours' => 7]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $matching->id, 'activity_id' => $activity->id, 'hours' => 2]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $excluded->id, 'activity_id' => $activity->id, 'hours' => 9]);

    $totals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $statusA->id)
        ->call('applyFilters')
        ->get('listTotals');

    expect($totals['estimated'])->toBe(5.0)
        ->and($totals['spent'])->toBe(2.0);
});

test('group headings carry per-group counts plus estimated and spent totals', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $statusNew = IssueStatus::factory()->create(['name' => 'New']);
    $statusDone = IssueStatus::factory()->create(['name' => 'Done']);
    $newA = Issue::factory()->for($project)->create(['status_id' => $statusNew->id, 'estimated_hours' => 3]);
    Issue::factory()->for($project)->create(['status_id' => $statusNew->id, 'estimated_hours' => 1]);
    Issue::factory()->for($project)->create(['status_id' => $statusDone->id, 'estimated_hours' => 8]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $newA->id, 'activity_id' => $activity->id, 'hours' => 2.5]);

    $groupTotals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id')
        ->get('groupTotals');

    expect($groupTotals['New']['count'])->toBe(2)
        ->and($groupTotals['New']['estimated'])->toBe(4.0)
        ->and($groupTotals['New']['spent'])->toBe(2.5)
        ->and($groupTotals['Done']['count'])->toBe(1)
        ->and($groupTotals['Done']['estimated'])->toBe(8.0)
        ->and($groupTotals['Done']['spent'])->toBe(0.0);
});
