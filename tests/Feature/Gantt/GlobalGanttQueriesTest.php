<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Renders the global Gantt for a member of $count projects, each with an issue targeting one of
 * its versions, and returns the number of queries the render took.
 */
function globalGanttQueriesFor(string $label, int $count): int
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_gantt', 'view_issues']]);
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    foreach (range(1, $count) as $i) {
        // Private, so each render sees exactly its own projects through the memberships.
        $project = Project::factory()->create(['name' => "{$label} {$i}", 'identifier' => "gantt-{$label}-{$i}", 'is_public' => false]);
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
        $version = Version::factory()->for($project)->create(['due_date' => '2026-02-10']);
        Issue::factory()->for($project)->create([
            'tracker_id' => $tracker->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'fixed_version_id' => $version->id,
            'start_date' => '2026-01-05',
            'due_date' => '2026-01-20',
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($user)->test('gantt.global-index')->assertOk();

    return $queries;
}

test('the global Gantt does not run more queries for every extra project', function () {
    $few = globalGanttQueriesFor('few', 2);
    $many = globalGanttQueriesFor('many', 10);

    // Eight more projects, each with an issue and a version, must not cost a query per project:
    // the memberships, modules, filter options, milestones and version progress are all fetched
    // for every project at once (this page once ran about ten queries per project).
    expect($many - $few)->toBeLessThanOrEqual(2, "2 projects: {$few} queries, 10 projects: {$many} queries");
});
