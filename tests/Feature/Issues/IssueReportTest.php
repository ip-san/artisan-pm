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

function reportMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the tracker grid counts issues by tracker and status', function () {
    $project = Project::factory()->create();
    $user = reportMember($project);
    $trackerA = Tracker::factory()->create(['name' => 'Bug']);
    $trackerB = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$trackerA->id, $trackerB->id]);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory()->for($project)->count(2)->create([
        'tracker_id' => $trackerA->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
    ]);
    Issue::factory()->for($project)->create([
        'tracker_id' => $trackerB->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
    ]);

    $grid = Livewire::actingAs($user)
        ->test('issues.report', ['project' => $project])
        ->get('trackerGrid');

    $bugRow = collect($grid['rows'])->firstWhere('label', 'Bug');
    $featureRow = collect($grid['rows'])->firstWhere('label', 'Feature');

    expect($grid['counts'][$bugRow['key']][$status->id])->toBe(2)
        ->and($grid['counts'][$featureRow['key']][$status->id])->toBe(1);
});

test('issues with a null category are counted under a none bucket', function () {
    $project = Project::factory()->create();
    $user = reportMember($project);
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'category_id' => null,
    ]);

    $grid = Livewire::actingAs($user)
        ->test('issues.report', ['project' => $project])
        ->get('categoryGrid');

    $noneRow = collect($grid['rows'])->firstWhere('key', 'none');

    expect($noneRow)->not->toBeNull()
        ->and($grid['counts']['none'][$status->id])->toBe(1);
});

test('counts are scoped to the current project only', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = reportMember($project);
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory()->for($otherProject)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
    ]);

    $grid = Livewire::actingAs($user)
        ->test('issues.report', ['project' => $project])
        ->get('trackerGrid');

    expect($grid['counts'])->toBe([]);
});

test('a user without view_issues cannot open the report', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('issues.report', ['project' => $project])->assertForbidden();
});
