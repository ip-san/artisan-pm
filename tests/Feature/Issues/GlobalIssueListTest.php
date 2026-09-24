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
use App\Models\Version;
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

/**
 * @param  array<int, string>  $permissions
 */
function globalBulkMember(User $user, Project $project, array $permissions = ['view_issues', 'edit_issues', 'delete_issues']): void
{
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'assignable' => true]));
}

test('a cross-project selection is bulk edited across its projects', function () {
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();
    $user = User::factory()->create();
    globalBulkMember($user, $alpha);
    globalBulkMember($user, $beta);
    $a = globalListIssue($alpha);
    $b = globalListIssue($beta);

    Livewire::actingAs($user)->test('issues.index')
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->assertSeeHtml('bulk-edit-form')
        ->set('bulkDoneRatio', 40)
        ->set('bulkAssignedToId', $user->id)
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    expect($a->fresh()->done_ratio)->toBe(40)->and($b->fresh()->done_ratio)->toBe(40)
        ->and($a->fresh()->assigned_to_id)->toBe($user->id)->and($b->fresh()->assigned_to_id)->toBe($user->id);
});

test('only candidates common to every selected project are offered and accepted', function () {
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();
    $user = User::factory()->create();
    globalBulkMember($user, $alpha);
    globalBulkMember($user, $beta);
    $alphaOnly = User::factory()->create();
    globalBulkMember($alphaOnly, $alpha, ['view_issues']);
    $alphaVersion = Version::factory()->for($alpha)->create(['name' => 'Alpha 1.0']);
    $a = globalListIssue($alpha);
    $b = globalListIssue($beta);

    $list = Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $a->id, (string) $b->id]);

    expect($list->get('projectMembers')->pluck('id')->all())->toBe([$user->id])
        ->and($list->get('projectVersions'))->toBeEmpty()
        ->and($list->get('bulkCategories'))->toBeEmpty();

    $list->set('bulkAssignedToId', $alphaOnly->id)->call('applyBulkEdit')->assertHasErrors('bulkAssignedToId');
    Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $a->id, (string) $b->id])
        ->set('bulkFixedVersionId', $alphaVersion->id)->call('applyBulkEdit')->assertHasErrors('bulkFixedVersionId');

    expect($a->fresh()->assigned_to_id)->toBeNull()->and($a->fresh()->fixed_version_id)->toBeNull();

    Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $a->id])
        ->set('bulkAssignedToId', $alphaOnly->id)->call('applyBulkEdit')->assertHasNoErrors();

    expect($a->fresh()->assigned_to_id)->toBe($alphaOnly->id);
});

test('a selection including an issue the user may not edit is refused as a whole', function () {
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();
    $user = User::factory()->create();
    globalBulkMember($user, $alpha);
    globalBulkMember($user, $beta, ['view_issues']);
    $a = globalListIssue($alpha);
    $b = globalListIssue($beta);

    Livewire::actingAs($user)->test('issues.index')
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->set('bulkDoneRatio', 70)
        ->call('applyBulkEdit')
        ->assertForbidden();

    Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $a->id, (string) $b->id])->call('contextUpdate', 'done_ratio', '70')->assertForbidden();

    expect($a->fresh()->done_ratio)->toBe(0)->and($b->fresh()->done_ratio)->toBe(0);
});

test('issues the user cannot see never join a cross-project selection', function () {
    $alpha = Project::factory()->create();
    $hidden = Project::factory()->create(['is_public' => false]);
    $user = User::factory()->create();
    Member::factory()->for($alpha)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'edit_issues', 'delete_issues'], 'issues_visibility' => IssueVisibility::Default->value]));
    $mine = globalListIssue($alpha);
    $private = globalListIssue($alpha, ['is_private' => true]);
    $elsewhere = globalListIssue($hidden);

    Livewire::actingAs($user)->test('issues.index')->call('openContextMenu', $elsewhere->id)->assertNotFound();
    Livewire::actingAs($user)->test('issues.index')->call('openContextMenu', $private->id)->assertNotFound();

    $list = Livewire::actingAs($user)->test('issues.index')->set('selected', [(string) $mine->id, (string) $private->id, (string) $elsewhere->id]);
    expect($list->get('selectedIssues')->pluck('id')->all())->toBe([$mine->id]);

    $list->call('applyBulkDelete');

    expect(Issue::query()->whereKey([$private->id, $elsewhere->id])->count())->toBe(2)->and($mine->fresh())->toBeNull();
});

test('the cross-project context menu links a single issue to its own project', function () {
    $alpha = Project::factory()->create();
    $user = User::factory()->create();
    globalBulkMember($user, $alpha);
    $issue = globalListIssue($alpha);

    Livewire::actingAs($user)->test('issues.index')
        ->call('openContextMenu', $issue->id)
        ->assertSet('selected', [(string) $issue->id])
        ->assertSee(route('issues.edit', [$alpha, $issue]), false);
});

test('a user without edit_issues anywhere gets no selection on the cross-project list', function () {
    $project = Project::factory()->create();
    $user = globalListMember($project);
    $issue = globalListIssue($project);

    Livewire::actingAs($user)->test('issues.index')
        ->assertDontSeeHtml('data-context-menu')
        ->call('openContextMenu', $issue->id)
        ->assertForbidden();
});

test('the cross-project CSV export contains only visible issues', function () {
    $alpha = Project::factory()->create();
    $hidden = Project::factory()->create(['is_public' => false]);
    $user = globalListMember($alpha);
    globalListIssue($alpha, ['subject' => 'Exported subject']);
    globalListIssue($hidden, ['subject' => 'Secret subject']);

    $component = Livewire::actingAs($user)->test('issues.index')->set('statusFilter', 'all')->call('exportCsv');
    $csv = base64_decode($component->effects['download']['content']);

    expect($component->effects['download']['name'])->toBe('issues.csv')
        ->and($csv)->toContain('Exported subject')->not->toContain('Secret subject');
});

test('the cross-project list groups by project with counts over visible issues only', function () {
    $alpha = Project::factory()->create(['name' => 'Alpha project']);
    $beta = Project::factory()->create(['name' => 'Beta project']);
    $hidden = Project::factory()->create(['name' => 'Hidden project', 'is_public' => false]);
    $user = globalListMember($alpha);
    Member::factory()->for($beta)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    globalListIssue($alpha);
    globalListIssue($alpha);
    globalListIssue($beta);
    globalListIssue($hidden);

    $list = Livewire::actingAs($user)->test('issues.index')->set('statusFilter', 'all')
        ->assertSeeHtml('<option value="project_id"')
        ->set('groupBy', 'project_id');

    expect($list->get('groupTotals')->map(fn (array $total) => $total['count'])->sortKeys()->all())->toBe(['Alpha project' => 2, 'Beta project' => 1])
        ->and($list->get('groupedIssues')->keys()->sort()->values()->all())->toBe(['Alpha project', 'Beta project']);
});
