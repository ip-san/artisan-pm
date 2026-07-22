<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\GanttService;
use Livewire\Livewire;

function ganttMember(Project $project, array $permissions = ['view_gantt']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int, author_id: int}
 */
function ganttIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
    ];
}

test('a member with view_gantt can see the gantt chart', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);

    Livewire::actingAs($user)->test('gantt.index', ['project' => $project])->assertOk();
});

test('a member without view_gantt is forbidden', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project, []);

    Livewire::actingAs($user)->test('gantt.index', ['project' => $project])->assertForbidden();
});

test('the issue tree is returned depth-first with correct depths', function () {
    $project = Project::factory()->create();
    $defaults = ganttIssueDefaults();

    $root = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Root']);
    $child = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Child', 'parent_id' => $root->id]);
    $grandchild = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Grandchild', 'parent_id' => $child->id]);
    $secondRoot = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'SecondRoot']);

    $rows = app(GanttService::class)->issueTree($project);

    expect($rows->pluck('id')->all())->toBe([$root->id, $child->id, $grandchild->id, $secondRoot->id])
        ->and($rows->pluck('depth')->all())->toBe([0, 1, 2, 0]);
});

test('an issue tree only includes issues from the given project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $defaults = ganttIssueDefaults();

    $issue = Issue::factory()->for($project)->create($defaults);
    Issue::factory()->for($otherProject)->create($defaults);

    $rows = app(GanttService::class)->issueTree($project);

    expect($rows->pluck('id')->all())->toBe([$issue->id]);
});

test('an issue without both start and due dates has no bar range', function () {
    $project = Project::factory()->create();
    $defaults = ganttIssueDefaults();
    Issue::factory()->for($project)->create([...$defaults, 'start_date' => null, 'due_date' => null]);

    $rows = app(GanttService::class)->issueTree($project);

    expect($rows->first()->hasDateRange())->toBeFalse();
});

test('the chart shows an empty-state message when no issues have a date range', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    Issue::factory()->for($project)->create(ganttIssueDefaults());

    Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->assertSee('開始日・期日が設定された課題がありません');
});

test('bar percentage helpers reject a row without a date range', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    $defaults = ganttIssueDefaults();
    Issue::factory()->for($project)->create([...$defaults, 'start_date' => null, 'due_date' => null]);

    $component = Livewire::actingAs($user)->test('gantt.index', ['project' => $project]);
    $row = $component->get('rows')->first();

    expect(fn () => $component->instance()->barLeftPercent($row))->toThrow(LogicException::class)
        ->and(fn () => $component->instance()->barWidthPercent($row))->toThrow(LogicException::class);
});

test('bar position percentages are computed correctly against the overall date range', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    $defaults = ganttIssueDefaults();

    // Overall range: 2026-01-01 .. 2026-01-31 (31 days).
    Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Full', 'start_date' => '2026-01-01', 'due_date' => '2026-01-31']);
    $mid = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Mid', 'start_date' => '2026-01-11', 'due_date' => '2026-01-20']);

    $component = Livewire::actingAs($user)->test('gantt.index', ['project' => $project]);
    $rows = $component->get('rows');
    $midRow = $rows->firstWhere('id', $mid->id);

    // day 11 of a 31-day range starting day 1 → offset 10 days → 10/31 ≈ 32.26%
    expect($component->instance()->barLeftPercent($midRow))->toBeGreaterThan(30.0)->toBeLessThan(33.0);
    // 10-day span (Jan 11–20 inclusive) of 31 days ≈ 32.26%
    expect($component->instance()->barWidthPercent($midRow))->toBeGreaterThan(30.0)->toBeLessThan(33.0);
});

test('an active filter restricts the gantt tree to matching issues', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    $defaults = ganttIssueDefaults();
    $otherStatus = IssueStatus::factory()->create();

    $matching = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Matching']);
    $excluded = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Excluded', 'status_id' => $otherStatus->id]);

    $rows = Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $defaults['status_id'])
        ->call('applyFilters')
        ->get('rows');

    expect($rows->pluck('id'))->toContain($matching->id)
        ->and($rows->pluck('id'))->not->toContain($excluded->id);
});

test('a filtered child keeps its non-matching ancestors for depth coherence', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    $defaults = ganttIssueDefaults();
    $otherStatus = IssueStatus::factory()->create();

    // Parent does NOT match the filter; child does; unrelated root doesn't.
    $parent = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Parent', 'status_id' => $otherStatus->id]);
    $child = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Child', 'parent_id' => $parent->id]);
    $unrelated = Issue::factory()->for($project)->create([...$defaults, 'subject' => 'Unrelated', 'status_id' => $otherStatus->id]);

    $rows = Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $defaults['status_id'])
        ->call('applyFilters')
        ->get('rows');

    expect($rows->pluck('id')->all())->toBe([$parent->id, $child->id])
        ->and($rows->firstWhere('id', $child->id)->depth)->toBe(1)
        ->and($rows->pluck('id'))->not->toContain($unrelated->id);
});

test('with no filters active the full tree is returned unchanged', function () {
    $project = Project::factory()->create();
    $user = ganttMember($project);
    $defaults = ganttIssueDefaults();
    Issue::factory()->for($project)->count(3)->create($defaults);

    $rows = Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->get('rows');

    expect($rows)->toHaveCount(3);
});
