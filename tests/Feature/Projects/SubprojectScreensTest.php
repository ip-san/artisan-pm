<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Dashboard\SavedIssueQueryBlock;
use Livewire\Livewire;

/**
 * A3-06b: display_subprojects_issues on the gantt, calendar, time entry
 * list and report, activity, Atom feed, REST list and my-page query block.
 *
 * @return array{parent: Project, child: Project, hidden: Project}
 */
function screensTree(): array
{
    $parent = Project::factory()->create(['name' => 'Parent project']);
    $child = Project::factory()->create(['name' => 'Child project', 'parent_id' => $parent->id]);
    $hidden = Project::factory()->create(['name' => 'Hidden child', 'parent_id' => $parent->id, 'is_public' => false]);

    return ['parent' => $parent->fresh(), 'child' => $child->fresh(), 'hidden' => $hidden->fresh()];
}

/**
 * @param  array<int, string>  $permissions
 */
function screensViewer(array $projects, array $permissions = ['view_project', 'view_issues', 'view_time_entries', 'view_gantt', 'view_calendar', 'save_queries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);

    foreach ($projects as $project) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    return $user;
}

function screensIssue(Project $project, string $subject): Issue
{
    $tracker = Tracker::query()->first() ?? Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
        'start_date' => now()->startOfMonth()->addDays(3)->toDateString(),
        'due_date' => now()->startOfMonth()->addDays(5)->toDateString(),
    ]);
}

test('the gantt draws visible subproject issues only with the setting on', function () {
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = screensTree();
    $viewer = screensViewer([$parent, $child]);
    screensIssue($parent, 'Parent task');
    $childIssue = screensIssue($child, 'Child task');
    screensIssue($hidden, 'Hidden task');

    $off = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $parent])->get('rows')->pluck('subject')->all();

    Setting::set('display_subprojects_issues', true);
    $on = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $parent]);

    expect($off)->toBe(['Parent task'])
        ->and($on->get('rows')->pluck('subject')->all())->toBe(['Parent task', 'Child task']);
    $on->assertSee(route('issues.show', [$child, $childIssue]), false);
});

test('the gantt with subprojects draws a heading row per project, each followed by its issues (A3-14)', function () {
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = screensTree();
    $grandchild = Project::factory()->create(['name' => 'Grandchild project', 'parent_id' => $child->id]);
    [$parent, $child] = [$parent->fresh(), $child->fresh()];
    $viewer = screensViewer([$parent, $child, $grandchild->fresh()]);
    $parentTask = screensIssue($parent, 'Parent task');
    $grandchildTask = screensIssue($grandchild->fresh(), 'Grandchild task');
    screensIssue($hidden, 'Hidden task');
    $version = Version::factory()->for($grandchild)->create(['name' => 'Grandchild milestone', 'due_date' => now()->startOfMonth()->addDays(6)]);
    $grandchildTask->update(['fixed_version_id' => $version->id]);

    $off = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $parent]);
    expect($off->get('lines')->pluck('kind')->all())->toBe(['issue']);

    Setting::set('display_subprojects_issues', true);
    $on = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $parent]);

    $summary = $on->get('lines')->map(fn (array $line) => [
        $line['kind'],
        $line['depth'],
        match ($line['kind']) {
            'project' => $line['project']->name,
            'issue' => $line['row']->subject,
            'version' => $line['version']->name,
        },
    ])->all();

    expect($summary)->toBe([
        ['project', 0, 'Parent project'],
        ['issue', 1, 'Parent task'],
        ['project', 1, 'Child project'],
        ['project', 2, 'Grandchild project'],
        ['issue', 3, 'Grandchild task'],
        ['version', 3, 'Grandchild milestone'],
    ]);
    $on->assertSee('data-gantt-project="'.$child->id.'"', false)
        ->assertDontSee('Hidden child')
        ->assertDontSee('Hidden task')
        ->assertSee(route('issues.show', [$parent, $parentTask]), false);
});

test('the calendar shows visible subproject issues only with the setting on', function () {
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = screensTree();
    $viewer = screensViewer([$parent, $child]);
    screensIssue($parent, 'Parent task');
    $childIssue = screensIssue($child, 'Child task');
    screensIssue($hidden, 'Hidden task');

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $parent])
        ->assertSee('Parent task')->assertDontSee('Child task');

    Setting::set('display_subprojects_issues', true);

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $parent])
        ->assertSee('Parent task')->assertSee('Child task')->assertDontSee('Hidden task')
        ->assertSee(route('issues.show', [$child, $childIssue]), false);
});

test('the time entry list and report take in visible subproject entries with the setting on', function () {
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = screensTree();
    $viewer = screensViewer([$parent, $child]);
    $own = TimeEntry::factory()->for($parent)->create(['hours' => 1]);
    $sub = TimeEntry::factory()->for($child)->create(['hours' => 2]);
    TimeEntry::factory()->for($hidden)->create(['hours' => 4]);

    $offIds = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent])->get('timeEntries')->getCollection()->pluck('id')->all();

    Setting::set('display_subprojects_issues', true);
    $list = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent]);
    $report = Livewire::actingAs($viewer)->test('time-entries.report', ['project' => $parent]);

    expect($offIds)->toBe([$own->id])
        ->and($list->get('timeEntries')->getCollection()->pluck('id')->sort()->values()->all())->toBe([$own->id, $sub->id])
        ->and($report->get('report')->grandTotal)->toBe(3.0);
});

test('a subproject entry is listed but not deleted or bulk edited from the parent list', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'child' => $child] = screensTree();
    $viewer = screensViewer([$parent, $child], ['view_project', 'view_time_entries', 'log_time', 'edit_time_entries']);
    $sub = TimeEntry::factory()->for($child)->create(['hours' => 2]);

    $list = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent])
        ->assertSee(route('time-entries.edit', [$child, $sub]), false);

    $list->call('deleteEntry', $sub->id)->assertStatus(404);

    Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent])
        ->set('selected', [(string) $sub->id])
        ->call('applyBulkDelete')
        ->assertNotFound();

    expect(TimeEntry::query()->whereKey($sub->id)->exists())->toBeTrue();
});

test('own-entries-only visibility still applies in a subproject', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'child' => $child] = screensTree();
    $viewer = screensViewer([$parent]);
    $ownOnly = Role::factory()->create(['permissions' => ['view_project', 'view_time_entries'], 'time_entries_visibility' => 'own']);
    Member::factory()->for($child)->for($viewer)->create()->roles()->attach($ownOnly);
    $mine = TimeEntry::factory()->for($child)->create(['user_id' => $viewer->id]);
    TimeEntry::factory()->for($child)->create();

    $ids = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $parent])->get('timeEntries')->getCollection()->pluck('id')->all();

    expect($ids)->toBe([$mine->id]);
});

test('the activity takes in subprojects by default only with the setting on', function () {
    ['parent' => $parent] = screensTree();
    $viewer = screensViewer([$parent]);

    expect(Livewire::actingAs($viewer)->test('activity.index', ['project' => $parent])->get('withSubprojects'))->toBeFalse();

    Setting::set('display_subprojects_issues', true);

    expect(Livewire::actingAs($viewer)->test('activity.index', ['project' => $parent])->get('withSubprojects'))->toBeTrue();
});

test('the Atom feed, the REST list and the my page block follow the setting', function () {
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = screensTree();
    $viewer = screensViewer([$parent, $child]);
    screensIssue($parent, 'Parent task');
    $childIssue = screensIssue($child, 'Child task');
    screensIssue($hidden, 'Hidden task');
    $query = SavedQuery::create(['name' => 'All', 'type' => 'issue', 'user_id' => $viewer->id, 'project_id' => $parent->id, 'visibility' => 'private', 'filters' => [], 'column_names' => []]);
    $apiIds = fn () => collect(test()->withHeaders(['X-Redmine-API-Key' => $viewer->regenerateApiKey()])
        ->getJson("/api/v1/projects/{$parent->id}/issues")->assertOk()->json('data'))->pluck('subject')->sort()->values()->all();

    expect($apiIds())->toBe(['Parent task']);
    $this->actingAs($viewer)->get(route('issues.atom', [$parent, 'statusFilter' => 'all']))->assertOk()->assertDontSee('Child task');

    Setting::set('display_subprojects_issues', true);

    expect($apiIds())->toBe(['Child task', 'Parent task'])
        ->and(app(SavedIssueQueryBlock::class)->rows($query, $viewer)->pluck('title')->filter(fn ($title) => str_contains($title, 'Child task'))->count())->toBe(1)
        ->and(app(SavedIssueQueryBlock::class)->rows($query, $viewer)->pluck('title')->filter(fn ($title) => str_contains($title, 'Hidden task'))->count())->toBe(0);
    $this->actingAs($viewer)->get(route('issues.atom', [$parent, 'statusFilter' => 'all']))->assertOk()
        ->assertSee('Child task')->assertDontSee('Hidden task')
        ->assertSee(route('issues.show', [$child, $childIssue]), false);
});
