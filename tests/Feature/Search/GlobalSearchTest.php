<?php

use App\Enums\RoleBuiltin;
use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function globalSearchMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the global search finds matches across every project the user can view_issues in', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = globalSearchMember($projectA, ['view_project', 'view_issues']);
    Member::factory()->for($projectB)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );

    $issueA = Issue::factory()->for($projectA)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'cross-project-token in A',
    ]);
    $issueB = Issue::factory()->for($projectB)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'cross-project-token in B',
    ]);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'cross-project-token')
        ->call('search')
        ->get('results');

    expect($results->pluck('title')->join(' '))
        ->toContain("#{$issueA->id}")
        ->toContain("#{$issueB->id}");
});

test('the global search excludes projects the user has no access to at all', function () {
    $visibleProject = Project::factory()->create();
    $hiddenProject = Project::factory()->create(['is_public' => false]);
    $user = globalSearchMember($visibleProject, ['view_project', 'view_issues']);

    Issue::factory()->for($hiddenProject)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'hidden-project-unique-token',
    ]);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'hidden-project-unique-token')
        ->call('search')
        ->get('results');

    expect($results)->toBeEmpty();
});

test('the global search finds a project by name and excludes projects the viewer cannot see', function () {
    $visibleProject = Project::factory()->create(['name' => 'Findable Project', 'is_public' => true]);
    $hiddenProject = Project::factory()->create(['name' => 'Findable Hidden Project', 'is_public' => false]);
    $user = globalSearchMember($visibleProject, ['view_project']);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'Findable')
        ->call('search')
        ->get('results');

    expect($results->where('type', 'project')->pluck('title'))
        ->toContain('Findable Project')
        ->not->toContain('Findable Hidden Project');
});

test('the global search finds a changeset by its commit message', function () {
    $project = Project::factory()->create();
    $user = globalSearchMember($project, ['view_project', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create(['comments' => 'global-search-commit-token']);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'global-search-commit-token')
        ->call('search')
        ->get('results');

    expect($results->pluck('type'))->toContain('changeset')
        ->and($results->first()->url)->toContain((string) $changeset->id);
});

test('the global search only surfaces news from projects the user can view_news in, even if visible otherwise', function () {
    $projectWithNewsAccess = Project::factory()->create();
    $projectWithoutNewsAccess = Project::factory()->create();
    $user = globalSearchMember($projectWithNewsAccess, ['view_project', 'view_news']);
    Member::factory()->for($projectWithoutNewsAccess)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project']])
    );

    News::factory()->for($projectWithNewsAccess)->create(['title' => 'shared-news-token here']);
    News::factory()->for($projectWithoutNewsAccess)->create(['title' => 'shared-news-token there']);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'shared-news-token')
        ->call('search')
        ->get('results');

    expect($results)->toHaveCount(1);
});

test('a #123 query on the global search jumps to that issue regardless of which project it belongs to', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = globalSearchMember($projectA, ['view_project', 'view_issues']);
    Member::factory()->for($projectB)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );

    $issue = Issue::factory()->for($projectB)->create();

    Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', "#{$issue->id}")
        ->call('search')
        ->assertRedirect(route('issues.show', [$projectB, $issue]));
});

test('a #123 query on the global search falls through to a normal search when the issue is not visible', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create(['is_public' => false]);
    $user = globalSearchMember($project, ['view_project', 'view_issues']);
    $foreignIssue = Issue::factory()->for($otherProject)->create();

    Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', "#{$foreignIssue->id}")
        ->call('search')
        ->assertNoRedirect();
});

test('a guest is redirected to login when visiting the global search', function () {
    $this->get(route('search.global-index'))->assertRedirect(route('login'));
});

test('the my-projects-only toggle excludes publicly visible projects the user is not a member of', function () {
    // Grants view_issues to any non-member on a public project — without
    // this, a stranger to $publicProject wouldn't see its issues at all
    // regardless of the toggle, which would make this test pass for the
    // wrong reason.
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_project', 'view_issues']]);

    $memberProject = Project::factory()->create();
    $publicProject = Project::factory()->create();
    $user = globalSearchMember($memberProject, ['view_project', 'view_issues']);

    Issue::factory()->for($memberProject)->create(['subject' => 'member-project-token']);
    Issue::factory()->for($publicProject)->create(['subject' => 'public-project-token']);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'project-token')
        ->set('myProjectsOnly', true)
        ->call('search')
        ->get('results');

    expect($results->pluck('title')->join(' '))->toContain('member-project-token')
        ->not->toContain('public-project-token');
});

test('without the my-projects-only toggle, publicly visible projects are still included', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_project', 'view_issues']]);

    $memberProject = Project::factory()->create();
    $publicProject = Project::factory()->create();
    $user = globalSearchMember($memberProject, ['view_project', 'view_issues']);

    Issue::factory()->for($publicProject)->create(['subject' => 'public-project-token']);

    $results = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'public-project-token')
        ->call('search')
        ->get('results');

    expect($results->pluck('title')->join(' '))->toContain('public-project-token');
});
