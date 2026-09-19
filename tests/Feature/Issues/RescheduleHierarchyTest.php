<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\IssueService;

/**
 * @param  array<string, mixed>  $attributes
 */
function hierarchyIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

function hierarchyPrecedes(Issue $from, Issue $to): void
{
    IssueRelation::create(['issue_from_id' => $from->id, 'issue_to_id' => $to->id, 'relation_type' => 'precedes']);
}

test('a successor that is a parent has its leaves moved, and its dates follow from them', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $predecessor = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-03']);
    $parent = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-06']);
    $early = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $late = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-05', 'due_date' => '2026-03-06']);
    hierarchyPrecedes($predecessor, $parent);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-03-10'], $actor);

    // Soonest start is 2026-03-11: the early leaf moves there keeping its
    // 2-day span, the late leaf (also before the new date) moves too.
    expect($early->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($early->fresh()->due_date->toDateString())->toBe('2026-03-13')
        ->and($late->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($late->fresh()->due_date->toDateString())->toBe('2026-03-12')
        ->and($parent->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($parent->fresh()->due_date->toDateString())->toBe('2026-03-13');
});

test('a leaf already starting on or after the new date stays put', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $predecessor = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-03']);
    $parent = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-20']);
    $early = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $late = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-15', 'due_date' => '2026-03-20']);
    hierarchyPrecedes($predecessor, $parent);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-03-10'], $actor);

    expect($early->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($late->fresh()->start_date->toDateString())->toBe('2026-03-15');
});

test('leaves are found at any depth and a middle parent is not moved directly', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $predecessor = hierarchyIssue($project, ['due_date' => '2026-03-03']);
    $top = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $middle = hierarchyIssue($project, ['parent_id' => $top->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $leaf = hierarchyIssue($project, ['parent_id' => $middle->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    hierarchyPrecedes($predecessor, $top);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-03-10'], $actor);

    expect($leaf->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($middle->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($top->fresh()->start_date->toDateString())->toBe('2026-03-11');
});

test('with parent_issue_dates off the successor parent is moved directly and its leaves stay', function () {
    Setting::set('parent_issue_dates', false);
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $predecessor = hierarchyIssue($project, ['due_date' => '2026-03-03']);
    $parent = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-06']);
    $leaf = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    hierarchyPrecedes($predecessor, $parent);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-03-10'], $actor);

    expect($parent->fresh()->start_date->toDateString())->toBe('2026-03-11')
        ->and($leaf->fresh()->start_date->toDateString())->toBe('2026-03-02');
});

test('a derived parent date change reschedules the parent\'s own successors', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $parent = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $leaf = hierarchyIssue($project, ['parent_id' => $parent->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $follower = hierarchyIssue($project, ['start_date' => '2026-03-05', 'due_date' => '2026-03-06']);
    hierarchyPrecedes($parent, $follower);

    app(IssueService::class)->update($leaf, ['due_date' => '2026-03-12'], $actor);

    expect($parent->fresh()->due_date->toDateString())->toBe('2026-03-12')
        ->and($follower->fresh()->start_date->toDateString())->toBe('2026-03-13')
        ->and($follower->fresh()->due_date->toDateString())->toBe('2026-03-14');
});

test('a chain through parents that loops back does not recurse forever', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->admin()->create();
    $a = hierarchyIssue($project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $child = hierarchyIssue($project, ['parent_id' => $a->id, 'start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
    $b = hierarchyIssue($project, ['start_date' => '2026-03-05', 'due_date' => '2026-03-06']);
    hierarchyPrecedes($a, $b);
    hierarchyPrecedes($b, $a);

    app(IssueService::class)->update($child, ['due_date' => '2026-03-09'], $actor);

    expect(Issue::query()->count())->toBe(3);
});
