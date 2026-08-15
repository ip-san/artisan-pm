<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Watcher;
use Livewire\Livewire;

function watcherProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function watchableIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a member with add_issue_watchers can add another member as a watcher', function () {
    $project = Project::factory()->create();
    $manager = watcherProjectMember($project, ['view_issues', 'add_issue_watchers']);
    $target = watcherProjectMember($project, ['view_issues']);
    $issue = watchableIssue($project);

    Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertHasNoErrors();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $target->id)->exists())->toBeTrue();
});

test('a member without add_issue_watchers cannot add another user as a watcher', function () {
    $project = Project::factory()->create();
    $user = watcherProjectMember($project, ['view_issues']);
    $target = watcherProjectMember($project, ['view_issues']);
    $issue = watchableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertForbidden();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $target->id)->exists())->toBeFalse();
});

test('a manager can remove another watcher', function () {
    $project = Project::factory()->create();
    $manager = watcherProjectMember($project, ['view_issues', 'add_issue_watchers']);
    $watching = watcherProjectMember($project, ['view_issues']);
    $issue = watchableIssue($project);
    $issue->watchers()->create(['user_id' => $watching->id]);

    Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('removeWatcher', $watching->id);

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $watching->id)->exists())->toBeFalse();
});

test('a non-member of the project cannot be added as a watcher', function () {
    $project = Project::factory()->create();
    $manager = watcherProjectMember($project, ['view_issues', 'add_issue_watchers']);
    $outsider = User::factory()->create();
    $issue = watchableIssue($project);

    Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('newWatcherId', $outsider->id)
        ->call('addWatcher')
        ->assertHasErrors(['newWatcherId']);
});

test('a user who only belongs to the URL project cannot be added as a watcher of another project\'s issue', function () {
    // Defense in depth behind the route's ->scopeBindings(): even if the
    // component is somehow handed a $project that does not own $issue, the
    // watcher candidate list and its validation must follow the issue's own
    // project. Before this was scoped to $this->issue, a manager on the
    // owning project could attach any member of the unrelated URL project.
    $owner = Project::factory()->create();
    $manager = watcherProjectMember($owner, ['view_issues', 'add_issue_watchers']);
    $issue = watchableIssue($owner);

    $unrelated = Project::factory()->create();
    $outsider = watcherProjectMember($unrelated, ['view_issues']);

    $component = Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $unrelated, 'issue' => $issue]);

    expect($component->instance()->watcherCandidates->pluck('id'))->not->toContain($outsider->id);

    $component->set('newWatcherId', $outsider->id)
        ->call('addWatcher')
        ->assertHasErrors('newWatcherId');

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $outsider->id)->exists())->toBeFalse();
});
