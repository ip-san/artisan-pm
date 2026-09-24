<?php

use App\Enums\IssueVisibility;
use App\Enums\VersionSharing;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function globalGanttMember(Project $project, array $permissions = ['view_gantt', 'view_issues'], ?User $user = null, ?Role $role = null): User
{
    $user ??= User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        $role ?? Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function globalGanttIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-01-05',
        'due_date' => '2026-01-20',
        ...$attributes,
    ]);
}

/**
 * @return array<int, string>
 */
function globalGanttLineKeys($component): array
{
    return $component->get('lines')->map(fn (array $line) => match ($line['kind']) {
        'project' => 'p'.$line['project']->id.'@'.$line['depth'],
        'issue' => 'i'.$line['row']->id.'@'.$line['depth'],
        'version' => 'v'.$line['version']->id.'@'.$line['depth'],
    })->all();
}

test('the cross-project chart is reachable from the header and a guest is sent to login', function () {
    $project = Project::factory()->create();
    $user = globalGanttMember($project);

    $this->actingAs($user)->get(route('gantt.global-index'))->assertOk()->assertSee(route('gantt.global-index'));
    auth()->logout();
    $this->get(route('gantt.global-index'))->assertRedirect(route('login'));
});

test('issues are grouped under their project in hierarchy order', function () {
    $parent = Project::factory()->create(['name' => 'Parent']);
    $child = Project::factory()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    $other = Project::factory()->create(['name' => 'Other']);
    $user = globalGanttMember($parent);
    globalGanttMember($child, user: $user);
    globalGanttMember($other, user: $user);

    $childIssue = globalGanttIssue($child);
    $parentIssue = globalGanttIssue($parent);
    $subtask = globalGanttIssue($parent, ['parent_id' => $parentIssue->id]);
    $otherIssue = globalGanttIssue($other);

    $component = Livewire::actingAs($user)->test('gantt.global-index');

    $parentFirst = $parent->fresh()->_lft < $other->fresh()->_lft;
    $parentGroup = ["p{$parent->id}@0", "i{$parentIssue->id}@1", "i{$subtask->id}@2", "p{$child->id}@1", "i{$childIssue->id}@2"];
    $otherGroup = ["p{$other->id}@0", "i{$otherIssue->id}@1"];

    expect(globalGanttLineKeys($component))->toBe($parentFirst ? [...$parentGroup, ...$otherGroup] : [...$otherGroup, ...$parentGroup]);
    $component->assertSee('Parent')->assertSee('Child');
});

test('a subtask in another project is drawn under its own project', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $user = globalGanttMember($a);
    globalGanttMember($b, user: $user);
    $parentIssue = globalGanttIssue($a);
    $foreignChild = globalGanttIssue($b, ['parent_id' => $parentIssue->id]);

    $keys = globalGanttLineKeys(Livewire::actingAs($user)->test('gantt.global-index'));

    expect($keys)->toContain("i{$parentIssue->id}@1")->toContain("i{$foreignChild->id}@1");
});

test('a project without view_gantt contributes no rows even with view_issues', function () {
    $gantt = Project::factory()->create();
    $issuesOnly = Project::factory()->create(['name' => 'IssuesOnlyProject']);
    $user = globalGanttMember($gantt);
    globalGanttMember($issuesOnly, ['view_issues'], $user);
    $shown = globalGanttIssue($gantt);
    $hidden = globalGanttIssue($issuesOnly, ['subject' => 'Not on the chart']);

    $component = Livewire::actingAs($user)->test('gantt.global-index');

    expect($component->get('rows')->pluck('id')->all())->toBe([$shown->id]);
    $component->assertDontSee('Not on the chart')->assertDontSee('IssuesOnlyProject');
});

test('each project keeps its own issue visibility rules', function () {
    $allProject = Project::factory()->create();
    $ownProject = Project::factory()->create();
    $user = globalGanttMember($allProject);
    globalGanttMember($ownProject, user: $user, role: Role::factory()->create([
        'permissions' => ['view_gantt', 'view_issues'],
        'issues_visibility' => IssueVisibility::Own->value,
    ]));
    $other = User::factory()->create();

    $othersInAll = globalGanttIssue($allProject, ['author_id' => $other->id]);
    $othersInOwn = globalGanttIssue($ownProject, ['author_id' => $other->id, 'subject' => 'Someone elses issue']);
    $mineInOwn = globalGanttIssue($ownProject, ['author_id' => $user->id]);

    $component = Livewire::actingAs($user)->test('gantt.global-index');

    expect($component->get('rows')->pluck('id')->all())
        ->toContain($othersInAll->id, $mineInOwn->id)
        ->not->toContain($othersInOwn->id);
    $component->assertDontSee('Someone elses issue');
});

test('private issues and trackers the role may not view stay hidden', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $user = globalGanttMember($project, role: Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create([
        'permissions' => ['view_gantt', 'view_issues'],
        'issues_visibility' => IssueVisibility::Default->value,
    ]));
    $other = User::factory()->create();

    $visible = globalGanttIssue($project, ['tracker_id' => $bug->id]);
    $wrongTracker = globalGanttIssue($project, ['tracker_id' => $feature->id]);
    $private = globalGanttIssue($project, ['tracker_id' => $bug->id, 'is_private' => true, 'author_id' => $other->id]);

    $rows = Livewire::actingAs($user)->test('gantt.global-index')->get('rows')->pluck('id')->all();

    expect($rows)->toBe([$visible->id])
        ->not->toContain($wrongTracker->id)
        ->not->toContain($private->id);
});

test('an active filter restricts the chart to matching issues across projects', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $user = globalGanttMember($a);
    globalGanttMember($b, user: $user);
    $wanted = IssueStatus::factory()->create();
    $match = globalGanttIssue($a, ['status_id' => $wanted->id]);
    globalGanttIssue($b);

    $component = Livewire::actingAs($user)->test('gantt.global-index')
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $wanted->id)
        ->call('applyFilters');

    expect($component->get('rows')->pluck('id')->all())->toBe([$match->id])
        ->and(globalGanttLineKeys($component))->toBe(["p{$a->id}@0", "i{$match->id}@1"]);
});

test('gantt_items_limit counts every row of the whole chart', function () {
    Setting::set('gantt_items_limit', 3);
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $user = globalGanttMember($a);
    globalGanttMember($b, user: $user);
    globalGanttIssue($a);
    globalGanttIssue($a);
    globalGanttIssue($b);

    $component = Livewire::actingAs($user)->test('gantt.global-index');

    expect($component->get('lines'))->toHaveCount(3)
        ->and($component->get('allLines'))->toHaveCount(5)
        ->and($component->get('rowsTruncated'))->toBeTrue();
    $component->assertSee('先頭3行だけを表示');
});

test('a shared version targeted by an issue is a milestone of that issue project', function () {
    $owner = Project::factory()->create();
    $user_project = Project::factory()->create();
    $user = globalGanttMember($user_project);
    $shared = Version::factory()->for($owner)->create(['name' => 'SharedRelease', 'sharing' => VersionSharing::System->value, 'due_date' => '2026-01-25']);
    $unshared = Version::factory()->for($owner)->create(['name' => 'OwnerOnly', 'sharing' => VersionSharing::None->value, 'due_date' => '2026-01-26']);
    $issue = globalGanttIssue($user_project, ['fixed_version_id' => $shared->id]);

    $component = Livewire::actingAs($user)->test('gantt.global-index');

    expect(globalGanttLineKeys($component))->toBe(["p{$user_project->id}@0", "i{$issue->id}@1", "v{$shared->id}@1"]);
    $component->assertSee('SharedRelease')->assertDontSee('OwnerOnly');
});

test('relations are drawn between issues of different projects when both are on the chart', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $user = globalGanttMember($a);
    globalGanttMember($b, user: $user);
    $from = globalGanttIssue($a);
    $to = globalGanttIssue($b, ['start_date' => '2026-01-21', 'due_date' => '2026-01-30']);
    IssueRelation::create(['issue_from_id' => $from->id, 'issue_to_id' => $to->id, 'relation_type' => 'precedes']);

    expect(Livewire::actingAs($user)->test('gantt.global-index')->get('relationLines'))->toHaveCount(1);
});
