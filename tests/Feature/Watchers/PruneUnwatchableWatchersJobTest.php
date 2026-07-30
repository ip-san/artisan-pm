<?php

use App\Enums\ProjectStatus;
use App\Jobs\PruneUnwatchableWatchersJob;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;

function watchableIssueForPrune(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a watcher who lost project membership on a private project is pruned', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);
    $issue = watchableIssueForPrune($project);
    $issue->watchers()->create(['user_id' => $user->id]);

    // The user was a member when they watched (and could view the issue),
    // but their membership is revoked before the sweep runs — matches
    // Redmine's own scenario for why Watcher.prune exists at all.
    $member->delete();

    (new PruneUnwatchableWatchersJob)->handle();

    expect($issue->watchers()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a watcher who still has view access is left alone', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);
    $issue = watchableIssueForPrune($project);
    $issue->watchers()->create(['user_id' => $user->id]);

    (new PruneUnwatchableWatchersJob)->handle();

    expect($issue->watchers()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a watcher of a news item on an archived project is pruned', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_news']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);
    $news = News::factory()->for($project)->create();
    $news->watchers()->create(['user_id' => $user->id]);

    // status isn't mass-assignable (Project's #[Fillable] omits it), so a
    // plain ->update() call would silently no-op — set it directly instead.
    $project->status = ProjectStatus::Archived;
    $project->save();

    (new PruneUnwatchableWatchersJob)->handle();

    expect($news->watchers()->where('user_id', $user->id)->exists())->toBeFalse();
});
