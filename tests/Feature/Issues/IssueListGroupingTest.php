<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('group totals reflect the full filtered set, not just the current page', function () {
    Setting::set('default_issues_per_page', 5);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory(8)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id');

    // Only 5 of the 8 issues are on the current page, but the group total
    // must reflect all 8 — the whole point of computing it via SQL rather
    // than counting the already-paginated in-memory collection.
    expect($component->instance()->issues->count())->toBe(5)
        ->and($component->instance()->groupTotals[$status->name]['count'])->toBe(8);
});

test('group totals use the friendly assigned_to_id placeholder for unassigned issues', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory(3)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
        'assigned_to_id' => null,
    ]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('groupBy', 'assigned_to_id');

    expect($component->instance()->groupTotals['未割当']['count'])->toBe(3);
});
