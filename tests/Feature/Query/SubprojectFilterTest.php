<?php

use App\Enums\ProjectStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Livewire\Livewire;

/**
 * A1-17e: Redmine's subproject_id filter (project_statement) on the issue
 * and time entry lists.
 *
 * @return array{parent: Project, a: Project, b: Project, hidden: Project, archived: Project}
 */
function subFilterTree(): array
{
    $parent = Project::factory()->create(['name' => 'Parent']);
    $a = Project::factory()->create(['name' => 'Sub A', 'parent_id' => $parent->id]);
    $b = Project::factory()->create(['name' => 'Sub B', 'parent_id' => $parent->id]);
    $hidden = Project::factory()->create(['name' => 'Sub hidden', 'parent_id' => $parent->id, 'is_public' => false]);
    $archived = Project::factory()->create(['name' => 'Sub archived', 'parent_id' => $parent->id]);
    $archived->update(['status' => ProjectStatus::Archived]);

    return ['parent' => $parent->fresh(), 'a' => $a->fresh(), 'b' => $b->fresh(), 'hidden' => $hidden->fresh(), 'archived' => $archived->fresh()];
}

function subFilterViewer(Project ...$projects): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries', 'save_queries']]);

    foreach ($projects as $project) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    return $user;
}

function subFilterIssue(Project $project): Issue
{
    $tracker = Tracker::query()->first() ?? Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => "Issue of {$project->name}",
    ]);
}

/**
 * @param  array<int, int|string>  $values
 * @return array<int, string>
 */
function subFilterSubjects(User $viewer, Project $project, string $operator, array $values = []): array
{
    return Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('activeFilterKeys', ['subproject_id'])
        ->set('filterOperators', ['subproject_id' => $operator])
        ->set('filterValues', ['subproject_id' => array_map('strval', $values)])
        ->call('applyFilters')
        ->get('issues')->getCollection()->pluck('subject')->sort()->values()->all();
}

test('the filter is offered only on a project with subprojects, with the visible active ones as choices', function () {
    ['parent' => $parent, 'a' => $a, 'b' => $b] = subFilterTree();
    $viewer = subFilterViewer($parent, $a, $b);

    $field = IssueFilterFieldRegistry::forProject($parent, $viewer)->get('subproject_id');

    expect($field)->not->toBeNull()
        ->and($field->options())->toBe([$a->id => 'Sub A', $b->id => 'Sub B'])
        ->and(IssueFilterFieldRegistry::forProject($a, $viewer)->has('subproject_id'))->toBeFalse()
        ->and(IssueFilterFieldRegistry::forProjects(collect([$parent, $a]), $viewer)->has('subproject_id'))->toBeFalse();
});

test('the filter takes in chosen, other, all or no subprojects even with the setting off', function () {
    ['parent' => $parent, 'a' => $a, 'b' => $b, 'hidden' => $hidden, 'archived' => $archived] = subFilterTree();
    $viewer = subFilterViewer($parent, $a, $b);
    foreach ([$parent, $a, $b, $hidden, $archived] as $project) {
        subFilterIssue($project);
    }

    expect(subFilterSubjects($viewer, $parent, '=', [$a->id]))->toBe(['Issue of Parent', 'Issue of Sub A'])
        ->and(subFilterSubjects($viewer, $parent, 'in', [$a->id, $b->id]))->toBe(['Issue of Parent', 'Issue of Sub A', 'Issue of Sub B'])
        ->and(subFilterSubjects($viewer, $parent, '!', [$a->id]))->toBe(['Issue of Parent', 'Issue of Sub B'])
        ->and(subFilterSubjects($viewer, $parent, 'not_empty'))->toBe(['Issue of Parent', 'Issue of Sub A', 'Issue of Sub B'])
        ->and(subFilterSubjects($viewer, $parent, 'empty'))->toBe(['Issue of Parent']);
});

test('none narrows to the main project with the setting on', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'a' => $a] = subFilterTree();
    $viewer = subFilterViewer($parent, $a);
    subFilterIssue($parent);
    subFilterIssue($a);

    expect(subFilterSubjects($viewer, $parent, 'empty'))->toBe(['Issue of Parent']);
});

test('choosing a subproject the viewer cannot see or an archived one shows nothing from it', function () {
    ['parent' => $parent, 'a' => $a, 'hidden' => $hidden, 'archived' => $archived] = subFilterTree();
    $viewer = subFilterViewer($parent, $a);
    $outsider = Project::factory()->create();
    foreach ([$parent, $a, $hidden, $archived, $outsider] as $project) {
        subFilterIssue($project);
    }

    expect(subFilterSubjects($viewer, $parent, '=', [$hidden->id]))->toBe(['Issue of Parent'])
        ->and(subFilterSubjects($viewer, $parent, 'in', [$archived->id, $outsider->id]))->toBe(['Issue of Parent'])
        ->and(subFilterSubjects($viewer, $parent, '!', [$a->id]))->toBe(['Issue of Parent']);
});

test('a viewer with own-issues-only visibility in a subproject sees only their issues there', function () {
    ['parent' => $parent, 'a' => $a] = subFilterTree();
    $viewer = subFilterViewer($parent);
    $ownOnly = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => 'own']);
    Member::factory()->for($a)->for($viewer)->create()->roles()->attach($ownOnly);
    subFilterIssue($parent);
    subFilterIssue($a);
    subFilterIssue($a)->update(['author_id' => $viewer->id, 'subject' => 'Mine in Sub A']);

    expect(subFilterSubjects($viewer, $parent, 'not_empty'))->toBe(['Issue of Parent', 'Mine in Sub A']);
});

test('the REST list reads subproject_id in both forms', function () {
    ['parent' => $parent, 'a' => $a, 'b' => $b, 'hidden' => $hidden] = subFilterTree();
    $viewer = subFilterViewer($parent, $a, $b);
    foreach ([$parent, $a, $b, $hidden] as $project) {
        subFilterIssue($project);
    }
    $subjects = fn (string $query) => collect(test()->withHeaders(['X-Redmine-API-Key' => $viewer->regenerateApiKey()])
        ->getJson("/api/v1/projects/{$parent->id}/issues?{$query}")->assertOk()->json('data'))->pluck('subject')->sort()->values()->all();

    expect($subjects('subproject_id=*'))->toBe(['Issue of Parent', 'Issue of Sub A', 'Issue of Sub B'])
        ->and($subjects("f[]=subproject_id&op[subproject_id]==&v[subproject_id][]={$b->id}"))->toBe(['Issue of Parent', 'Issue of Sub B'])
        ->and($subjects("subproject_id={$hidden->id}"))->toBe(['Issue of Parent'])
        ->and($subjects(''))->toBe(['Issue of Parent']);
});

test('the time entry list and report read the filter too', function () {
    ['parent' => $parent, 'a' => $a, 'b' => $b, 'hidden' => $hidden] = subFilterTree();
    $viewer = subFilterViewer($parent, $a, $b);
    $own = TimeEntry::factory()->for($parent)->create(['hours' => 1]);
    $inA = TimeEntry::factory()->for($a)->create(['hours' => 2]);
    TimeEntry::factory()->for($b)->create(['hours' => 4]);
    TimeEntry::factory()->for($hidden)->create(['hours' => 8]);

    $list = fn (string $operator, array $values) => Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent])
        ->set('activeFilterKeys', ['subproject_id'])
        ->set('filterOperators', ['subproject_id' => $operator])
        ->set('filterValues', ['subproject_id' => array_map('strval', $values)])
        ->call('applyFilters');

    expect($list('=', [$a->id])->get('timeEntries')->getCollection()->pluck('id')->sort()->values()->all())->toBe([$own->id, $inA->id])
        ->and(TimeEntryFilterFieldRegistry::forProject($parent, $viewer)->get('subproject_id')?->options())->toBe([$a->id => 'Sub A', $b->id => 'Sub B']);

    $report = Livewire::actingAs($viewer)->test('time-entries.report', ['project' => $parent])
        ->set('activeFilterKeys', ['subproject_id'])
        ->set('filterOperators', ['subproject_id' => 'not_empty'])
        ->call('applyFilters');

    expect($report->get('report')->grandTotal)->toBe(7.0);
});
