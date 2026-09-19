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

/**
 * @param  array<int, string>  $permissions
 */
function reportDetailsMember(Project $project, array $permissions = ['view_issues'], string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility]));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reportDetailsIssue(Project $project, Tracker $tracker, IssueStatus $status, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('the details page splits each row into statuses, open, closed and total with a totals row', function () {
    $project = Project::factory()->create();
    $user = reportDetailsMember($project);
    $tracker = Tracker::factory()->create(['name' => 'Bug']);
    $project->trackers()->attach($tracker);
    $open = IssueStatus::factory()->create(['name' => 'Open', 'is_closed' => false]);
    $done = IssueStatus::factory()->create(['name' => 'Done', 'is_closed' => true]);
    reportDetailsIssue($project, $tracker, $open);
    reportDetailsIssue($project, $tracker, $open);
    reportDetailsIssue($project, $tracker, $done);

    $page = Livewire::actingAs($user)->test('issues.report-details', ['project' => $project, 'detail' => 'tracker']);

    expect($page->instance()->rowFigures($tracker->id))->toBe(['statuses' => [$open->id => 2, $done->id => 1], 'open' => 2, 'closed' => 1, 'total' => 3])
        ->and($page->instance()->totalFigures()['total'])->toBe(3);
    $page->assertSee('Bug')->assertSee('未完了')->assertSee('完了');
});

test('an unknown detail is a 404 and a viewer without view_issues is refused', function () {
    $project = Project::factory()->create();
    $user = reportDetailsMember($project);

    Livewire::actingAs($user)->test('issues.report-details', ['project' => $project, 'detail' => 'nonsense'])->assertNotFound();
    Livewire::actingAs(User::factory()->create())->test('issues.report-details', ['project' => $project, 'detail' => 'tracker'])->assertForbidden();
});

test('the report only counts issues the viewer may see', function () {
    $project = Project::factory()->create();
    $viewer = reportDetailsMember($project, ['view_issues'], 'default');
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    reportDetailsIssue($project, $tracker, $status);
    reportDetailsIssue($project, $tracker, $status, ['is_private' => true, 'author_id' => User::factory()->create()->id]);

    $grid = Livewire::actingAs($viewer)->test('issues.report', ['project' => $project])->get('trackerGrid');

    expect($grid['counts'][$tracker->id][$status->id])->toBe(1);
});

test('subprojects appear as a report section only when the setting includes them', function () {
    $project = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $project->id, 'name' => 'Child Project']);
    $user = reportDetailsMember($project);
    Member::factory()->for($child)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    reportDetailsIssue($child, $tracker, $status);

    Livewire::actingAs($user)->test('issues.report', ['project' => $project->fresh()])->assertDontSee('サブプロジェクト別');

    Setting::set('display_subprojects_issues', true);
    $page = Livewire::actingAs($user)->test('issues.report', ['project' => $project->fresh()]);
    $page->assertSee('サブプロジェクト別')->assertSee('Child Project');
    expect($page->get('subprojectGrid')['counts'][$child->id][$status->id])->toBe(1)
        ->and($page->get('trackerGrid')['counts'][$tracker->id][$status->id])->toBe(1);
});

test('a subproject the viewer cannot see is left out of the report', function () {
    $project = Project::factory()->create();
    $hidden = Project::factory()->private()->create(['parent_id' => $project->id]);
    $user = reportDetailsMember($project);
    Setting::set('display_subprojects_issues', true);
    reportDetailsIssue($hidden, Tracker::factory()->create(), IssueStatus::factory()->create());

    $page = Livewire::actingAs($user)->test('issues.report', ['project' => $project->fresh()]);

    expect($page->get('subprojectGrid')['rows'])->toBe([])->and($page->get('trackerGrid')['counts'])->toBe([]);
    $page->assertDontSee('サブプロジェクト別');
});

test('the details page exports a CSV with headings, rows and totals', function () {
    $project = Project::factory()->create();
    $user = reportDetailsMember($project);
    $tracker = Tracker::factory()->create(['name' => 'Bug']);
    $project->trackers()->attach($tracker);
    $open = IssueStatus::factory()->create(['name' => 'Open', 'is_closed' => false, 'position' => 1]);
    $done = IssueStatus::factory()->create(['name' => 'Done', 'is_closed' => true, 'position' => 2]);
    reportDetailsIssue($project, $tracker, $open);
    reportDetailsIssue($project, $tracker, $done);

    Livewire::actingAs($user)->test('issues.report-details', ['project' => $project, 'detail' => 'tracker'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            'report-tracker.csv',
            "\xEF\xBB\xBF".csvRow(['', 'Open', 'Done', '未完了', '完了', '合計']).csvRow(['Bug', 1, 1, 1, 1, 2]).csvRow(['合計', 1, 1, 1, 1, 2]),
        );
});

test('each section of the report links to its details page', function () {
    $project = Project::factory()->create();
    $user = reportDetailsMember($project);

    Livewire::actingAs($user)->test('issues.report', ['project' => $project])->assertSee(route('issues.report-details', [$project, 'tracker']), false);
});
