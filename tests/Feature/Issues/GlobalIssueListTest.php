<?php

use App\Enums\IssueVisibility;
use App\Enums\ProjectModuleKey;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function globalListIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

function globalListMember(Project $project, string $visibility = IssueVisibility::All->value): User
{
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => $visibility]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the global issue list only shows issues from projects the user can view_issues in', function () {
    $visibleProject = Project::factory()->create();
    $hiddenProject = Project::factory()->create();
    $user = globalListMember($visibleProject);

    $visible = globalListIssue($visibleProject, ['subject' => 'Visible issue']);
    $hidden = globalListIssue($hiddenProject, ['subject' => 'Hidden issue']);

    $ids = Livewire::actingAs($user)
        ->test('issues.index')
        ->set('statusFilter', 'all')
        ->instance()->issues->pluck('id');

    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

test('the global issue list excludes projects with issue tracking disabled', function () {
    $project = Project::factory()->create();
    $user = globalListMember($project);
    $project->syncModules(collect(ProjectModuleKey::cases())->reject(fn ($m) => $m === ProjectModuleKey::IssueTracking)->all());

    $issue = globalListIssue($project);

    $ids = Livewire::actingAs($user)
        ->test('issues.index')
        ->set('statusFilter', 'all')
        ->instance()->issues->pluck('id');

    expect($ids)->not->toContain($issue->id);
});

test('cross-project visibility is bucketed per project: all-visibility here, own-only there', function () {
    $allProject = Project::factory()->create();
    $ownProject = Project::factory()->create();

    $user = globalListMember($allProject, IssueVisibility::All->value);
    Member::factory()->for($ownProject)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::Own->value]));

    $other = User::factory()->create();
    $othersIssueInAllProject = globalListIssue($allProject, ['author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $othersIssueInOwnProject = globalListIssue($ownProject, ['author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $myIssueInOwnProject = globalListIssue($ownProject, ['author_id' => $user->id]);

    $ids = Livewire::actingAs($user)
        ->test('issues.index')
        ->set('statusFilter', 'all')
        ->instance()->issues->pluck('id');

    expect($ids)->toContain($othersIssueInAllProject->id)
        ->toContain($myIssueInOwnProject->id)
        ->not->toContain($othersIssueInOwnProject->id);
});

test('the global issue list can be filtered by project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = globalListMember($projectA);
    Member::factory()->for($projectB)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::All->value]));

    $issueA = globalListIssue($projectA);
    $issueB = globalListIssue($projectB);

    $component = Livewire::actingAs($user)->test('issues.index')->set('statusFilter', 'all');
    $component->set('activeFilterKeys', ['project_id'])
        ->set('filterOperators.project_id', '=')
        ->set('filterValues.project_id', [(string) $projectA->id])
        ->call('applyFilters');

    $ids = $component->instance()->issues->pluck('id');

    expect($ids)->toContain($issueA->id)->not->toContain($issueB->id);
});

test('a guest is redirected to login when visiting the global issue list', function () {
    $this->get(route('issues.global-index'))->assertRedirect(route('login'));
});

test('the cross-project list is the issue list without a project: it opens on a global default query', function () {
    $project = Project::factory()->create();
    $user = globalListMember($project);
    $issue = globalListIssue($project, ['subject' => 'Default query issue']);
    $default = Query::create([
        'name' => 'Everything by subject',
        'type' => QueryType::Issue->value,
        'user_id' => $user->id,
        'project_id' => null,
        'visibility' => QueryVisibility::Public->value,
        'filters' => ['status_id' => ['operator' => '*', 'values' => []]],
        'column_names' => ['project_id', 'subject', 'due_date'],
        'sort_criteria' => [['subject', 'asc']],
    ]);
    $projectQuery = Query::create([
        'name' => 'Project only',
        'type' => QueryType::Issue->value,
        'user_id' => $user->id,
        'project_id' => $project->id,
        'visibility' => QueryVisibility::Public->value,
        'filters' => [],
        'column_names' => ['subject'],
        'sort_criteria' => [],
    ]);
    Setting::set('default_issue_query', $default->id);

    Livewire::actingAs($user)->test('issues.index')
        ->assertSet('columns', ['project_id', 'subject', 'due_date'])
        ->assertSet('sortKey', 'subject')
        ->assertSee('Default query issue');

    Livewire::actingAs($user)->test('issues.index')->call('loadQuery', $projectQuery->id)->assertNotFound();
});

test('the cross-project list offers no bulk actions and refuses them', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'edit_issues', 'delete_issues', 'copy_issues']]));
    $issue = globalListIssue($project, ['subject' => 'Keep me']);

    $list = Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $issue->id]);

    $list->assertDontSeeHtml('data-context-menu')->assertDontSeeHtml('bulk-edit-form');
    $list->call('contextUpdate', 'done_ratio', '50')->assertForbidden();
    Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $issue->id])->call('applyBulkDelete')->assertNotFound();

    expect($issue->fresh())->not->toBeNull()->and($issue->fresh()->done_ratio)->toBe(0);
});
