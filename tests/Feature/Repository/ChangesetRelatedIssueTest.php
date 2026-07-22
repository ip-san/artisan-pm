<?php

use App\Models\Changeset;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function relatedIssueMember(Project $project, array $permissions = ['view_changesets', 'view_issues', 'manage_related_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * The changeset view renders its diff, which shells out to the (fake,
 * nonexistent) repository path — pre-seeding the immutable diff cache
 * keeps these tests off the git binary entirely.
 */
function fakeChangesetDiff(Changeset $changeset): void
{
    Cache::forever("changeset:{$changeset->id}:diff", '');
}

test('a member with manage_related_issues can link an issue to a changeset, with or without a # prefix', function () {
    $project = Project::factory()->create();
    $user = relatedIssueMember($project);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);
    $issue = Issue::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->set('newIssueReference', "#{$issue->id}")
        ->call('addRelatedIssue')
        ->assertHasNoErrors();

    expect($changeset->fresh()->issues->pluck('id'))->toContain($issue->id);
});

test('linking a nonexistent issue reports an error', function () {
    $project = Project::factory()->create();
    $user = relatedIssueMember($project);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->set('newIssueReference', '999999')
        ->call('addRelatedIssue')
        ->assertHasErrors(['newIssueReference']);
});

test('linking an issue the user cannot view is rejected the same as a nonexistent one', function () {
    $project = Project::factory()->create();
    $user = relatedIssueMember($project);
    $privateProject = Project::factory()->private()->create();
    $hiddenIssue = Issue::factory()->for($privateProject)->create();
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->set('newIssueReference', (string) $hiddenIssue->id)
        ->call('addRelatedIssue')
        ->assertHasErrors(['newIssueReference']);

    expect($changeset->fresh()->issues)->toHaveCount(0);
});

test('linking an already-linked issue reports an error instead of duplicating', function () {
    $project = Project::factory()->create();
    $user = relatedIssueMember($project);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);
    $issue = Issue::factory()->for($project)->create();
    $changeset->issues()->attach($issue->id);

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->set('newIssueReference', (string) $issue->id)
        ->call('addRelatedIssue')
        ->assertHasErrors(['newIssueReference']);

    expect($changeset->fresh()->issues)->toHaveCount(1);
});

test('a member with manage_related_issues can unlink an issue', function () {
    $project = Project::factory()->create();
    $user = relatedIssueMember($project);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);
    $issue = Issue::factory()->for($project)->create();
    $changeset->issues()->attach($issue->id);

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->call('removeRelatedIssue', $issue->id);

    expect($changeset->fresh()->issues)->toHaveCount(0);
});

test('a member without manage_related_issues cannot link or unlink, and sees no controls', function () {
    $project = Project::factory()->create();
    $viewer = relatedIssueMember($project, ['view_changesets', 'view_issues']);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create();
    fakeChangesetDiff($changeset);
    $issue = Issue::factory()->for($project)->create();
    $changeset->issues()->attach($issue->id);

    Livewire::actingAs($viewer)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->assertDontSee('課題を関連付け')
        ->set('newIssueReference', (string) $issue->id)
        ->call('addRelatedIssue')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->call('removeRelatedIssue', $issue->id)
        ->assertForbidden();

    expect($changeset->fresh()->issues)->toHaveCount(1);
});
