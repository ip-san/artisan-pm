<?php

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
use App\Support\Issues\SubprojectScope;
use Livewire\Livewire;

/**
 * @return array{parent: Project, child: Project, hidden: Project}
 */
function subprojectTree(): array
{
    $parent = Project::factory()->create(['name' => 'Parent project']);
    $child = Project::factory()->create(['name' => 'Child project', 'parent_id' => $parent->id]);
    $hidden = Project::factory()->create(['name' => 'Hidden child', 'parent_id' => $parent->id, 'is_public' => false]);

    return ['parent' => $parent->fresh(), 'child' => $child->fresh(), 'hidden' => $hidden->fresh()];
}

function subprojectViewer(Project ...$projects): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries']]);

    foreach ($projects as $project) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    return $user;
}

function subprojectIssue(Project $project, string $subject): Issue
{
    $tracker = Tracker::query()->first() ?? Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
    ]);
}

test('by default a project list shows only its own issues', function () {
    ['parent' => $parent, 'child' => $child] = subprojectTree();
    $viewer = subprojectViewer($parent, $child);
    subprojectIssue($parent, 'Own issue');
    subprojectIssue($child, 'Child issue');

    $subjects = Livewire::actingAs($viewer)->test('issues.index', ['project' => $parent])->set('statusFilter', 'all')->get('issues')->getCollection()->pluck('subject')->all();

    expect($subjects)->toBe(['Own issue']);
});

test('with the setting on the list also shows visible subproject issues and a project column', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'child' => $child, 'hidden' => $hidden] = subprojectTree();
    $viewer = subprojectViewer($parent, $child);
    subprojectIssue($parent, 'Own issue');
    subprojectIssue($child, 'Child issue');
    subprojectIssue($hidden, 'Not for this viewer');

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $parent])->set('statusFilter', 'all')->set('columns', ['subject', 'project_id']);
    $issues = $list->get('issues')->getCollection();

    expect($issues->pluck('subject')->all())->toEqualCanonicalizing(['Own issue', 'Child issue']);
    $child = $issues->firstWhere('subject', 'Child issue');
    expect($list->instance()->columnValue($child, 'project_id'))->toBe('Child project');
});

test('per-issue visibility still applies inside each subproject', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'child' => $child] = subprojectTree();
    $viewer = User::factory()->create();
    Member::factory()->for($parent)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => 'default']));
    Member::factory()->for($child)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => 'default']));
    subprojectIssue($child, 'Public in child');
    subprojectIssue($child, 'Private in child')->update(['is_private' => true]);

    $subjects = Livewire::actingAs($viewer)->test('issues.index', ['project' => $parent])->set('statusFilter', 'all')->get('issues')->getCollection()->pluck('subject')->all();

    expect($subjects)->toBe(['Public in child']);
});

test('a subproject the viewer cannot view issues in is left out', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'child' => $child] = subprojectTree();
    $viewer = User::factory()->create();
    Member::factory()->for($parent)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    Member::factory()->for($child)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    subprojectIssue($child, 'Invisible');
    subprojectIssue($parent, 'Visible');

    expect(SubprojectScope::projectsForIssues($parent, $viewer)->pluck('name')->all())->toBe(['Parent project'])
        ->and(Livewire::actingAs($viewer)->test('issues.index', ['project' => $parent])->set('statusFilter', 'all')->get('issues')->getCollection()->pluck('subject')->all())->toBe(['Visible']);
});

test('the project overview totals subproject hours only with the setting on and permission', function () {
    ['parent' => $parent, 'child' => $child] = subprojectTree();
    $viewer = subprojectViewer($parent, $child);
    TimeEntry::factory()->for($parent)->create(['hours' => 2]);
    TimeEntry::factory()->for($child)->create(['hours' => 3]);

    expect(Livewire::actingAs($viewer)->test('projects.show', ['project' => $parent])->get('totalSpentHours'))->toBe(2.0);

    Setting::set('display_subprojects_issues', true);
    expect(Livewire::actingAs($viewer)->test('projects.show', ['project' => $parent])->get('totalSpentHours'))->toBe(5.0);

    $noTimeViewer = User::factory()->create();
    Member::factory()->for($parent)->for($noTimeViewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_time_entries']]));
    Member::factory()->for($child)->for($noTimeViewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    expect(Livewire::actingAs($noTimeViewer)->test('projects.show', ['project' => $parent])->get('totalSpentHours'))->toBe(2.0);
});

test('a project without subprojects is unaffected by the setting', function () {
    Setting::set('display_subprojects_issues', true);
    $lonely = Project::factory()->create()->fresh();

    expect(SubprojectScope::projectsForIssues($lonely, User::factory()->create())->pluck('id')->all())->toBe([$lonely->id]);
});

test('the settings form stores the option', function () {
    $admin = User::factory()->admin()->create();

    expect(SubprojectScope::enabled())->toBeFalse();
    Livewire::actingAs($admin)->test('settings.index')->set('display_subprojects_issues', true)->call('save')->assertHasNoErrors();
    expect(SubprojectScope::enabled())->toBeTrue();
});
