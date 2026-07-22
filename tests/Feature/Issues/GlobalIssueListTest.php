<?php

use App\Enums\IssueVisibility;
use App\Enums\ProjectModuleKey;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
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
        ->test('issues.global-index')
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
        ->test('issues.global-index')
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
        ->test('issues.global-index')
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

    $component = Livewire::actingAs($user)->test('issues.global-index')->set('statusFilter', 'all');
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
