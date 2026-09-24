<?php

use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Issues\AssigneeChoice;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\SqlDialect;
use App\Support\Reports\IssueReport;
use Illuminate\Database\Connection;
use Livewire\Livewire;

/**
 * Shared hosting runs MySQL 8 / MariaDB 10.x: the raw SQL that differs
 * between databases goes through SqlDialect or the grammar. The suite runs
 * on PostgreSQL; the same tests pass with DB_CONNECTION=mysql / mariadb.
 */
function portableSqlConnection(string $driver): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);

    return $connection;
}

test('a value is cast to text with each database\'s spelling', function (string $driver, string $expected) {
    expect(SqlDialect::castAsText(portableSqlConnection($driver), 'issues.assigned_to_id'))->toBe($expected);
})->with([
    'mysql' => ['mysql', 'CAST(issues.assigned_to_id AS CHAR)'],
    'mariadb' => ['mariadb', 'CAST(issues.assigned_to_id AS CHAR)'],
    // CHAR would be character(1) on PostgreSQL and cut the id short.
    'pgsql' => ['pgsql', 'CAST(issues.assigned_to_id AS VARCHAR)'],
    'sqlite' => ['sqlite', 'CAST(issues.assigned_to_id AS VARCHAR)'],
]);

test('the sort by assignee quotes the groups table, a reserved word on MySQL', function () {
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject(Project::factory()->create(), User::factory()->admin()->create()));
    $sql = $engine->applySort(Issue::query(), [['assigned_to_id', 'asc']])->toSql();
    $quotedGroups = Issue::query()->getQuery()->getGrammar()->wrapTable('groups');

    expect($sql)->toContain("FROM {$quotedGroups} WHERE");
});

test('the report, grouping and sort by assignee run with user, group and no assignee', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => true]);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Zed']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $group = Group::factory()->create(['name' => 'Alpha team']);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($role);

    $attributes = ['tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id];
    $userIssue = Issue::factory()->for($project)->create([...$attributes, 'assigned_to_id' => $user->id, 'estimated_hours' => 2]);
    $groupIssue = Issue::factory()->for($project)->create([...$attributes, 'assigned_to_group_id' => $group->id, 'estimated_hours' => 3]);
    $unassigned = Issue::factory()->for($project)->create([...$attributes, 'estimated_hours' => 5]);

    $counts = (new IssueReport($project, $admin))->counts('assigned_to');
    expect($counts[(string) $user->id][$status->id])->toBe(1)
        ->and($counts[AssigneeChoice::forGroup($group)][$status->id])->toBe(1)
        ->and($counts['none'][$status->id])->toBe(1);

    $totals = Livewire::actingAs($admin)->test('issues.index', ['project' => $project])
        ->set('groupBy', 'assigned_to_id')
        ->instance()->groupTotals;
    expect($totals['Zed']['count'])->toBe(1)
        ->and($totals['Alpha team']['count'])->toBe(1)
        ->and($totals['Alpha team']['estimated'])->toEqual(3.0)
        ->and($totals[__('未割当')]['count'])->toBe(1);

    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $admin));
    $sorted = $engine->applySort(Issue::query()->whereKey([$userIssue->id, $groupIssue->id]), [['assigned_to_id', 'asc']])->pluck('id')->all();
    expect($sorted)->toBe([$groupIssue->id, $userIssue->id])
        ->and($unassigned->exists)->toBeTrue();
});
