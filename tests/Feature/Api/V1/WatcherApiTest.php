<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Watcher;
use Laravel\Passport\Passport;

function apiWatcherMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => 1])->assertUnauthorized();
});

test('a member with add_issue_watchers can add another member as a watcher', function () {
    $project = Project::factory()->create();
    $manager = apiWatcherMember($project, ['view_issues', 'add_issue_watchers']);
    $target = apiWatcherMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => $target->id])
        ->assertNoContent();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $target->id)->exists())->toBeTrue();
});

test('a member without add_issue_watchers cannot add a watcher', function () {
    $project = Project::factory()->create();
    $user = apiWatcherMember($project, ['view_issues']);
    $target = apiWatcherMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => $target->id])
        ->assertForbidden();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $target->id)->exists())->toBeFalse();
});

test('a non-member of the project cannot be added as a watcher', function () {
    $project = Project::factory()->create();
    $manager = apiWatcherMember($project, ['view_issues', 'add_issue_watchers']);
    $outsider = User::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);
});

test('adding an already-watching user is idempotent', function () {
    $project = Project::factory()->create();
    $manager = apiWatcherMember($project, ['view_issues', 'add_issue_watchers']);
    $target = apiWatcherMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $target->id]);

    Passport::actingAs($manager);

    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => $target->id])
        ->assertNoContent();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $target->id)->count())->toBe(1);
});

test('a member with add_issue_watchers can remove a watcher', function () {
    $project = Project::factory()->create();
    $manager = apiWatcherMember($project, ['view_issues', 'add_issue_watchers']);
    $watching = apiWatcherMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $watching->id]);

    Passport::actingAs($manager);

    $this->deleteJson("/api/v1/issues/{$issue->id}/watchers/{$watching->id}")
        ->assertNoContent();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $watching->id)->exists())->toBeFalse();
});

test('a member without add_issue_watchers cannot remove a watcher', function () {
    $project = Project::factory()->create();
    $user = apiWatcherMember($project, ['view_issues']);
    $watching = apiWatcherMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $watching->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/issues/{$issue->id}/watchers/{$watching->id}")
        ->assertForbidden();

    expect(Watcher::where('watchable_id', $issue->id)->where('user_id', $watching->id)->exists())->toBeTrue();
});
