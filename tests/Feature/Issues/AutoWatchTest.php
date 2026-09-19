<?php

use App\Enums\UserStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Preferences\UserPreferences;
use Livewire\Livewire;

function autoWatchProject(): Project
{
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());

    return $project;
}

function autoWatchUser(Project $project, array $preferences = []): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'add_issues', 'edit_issues', 'add_issue_notes'], 'assignable' => true])
    );

    if ($preferences !== []) {
        UserPreferences::save($user, $preferences);
    }

    return $user->fresh();
}

/**
 * @return array<string, mixed>
 */
function autoWatchAttributes(Project $project, array $extra = []): array
{
    return [
        'project_id' => $project->id,
        'tracker_id' => $project->trackers->first()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Watched?',
        ...$extra,
    ];
}

test('by default the author and the assignee watch a new issue, as before', function () {
    $project = autoWatchProject();
    $author = autoWatchUser($project);
    $assignee = autoWatchUser($project);

    $issue = app(IssueService::class)->create(autoWatchAttributes($project, ['assigned_to_id' => $assignee->id]), $author);

    expect($issue->watchers()->pluck('user_id')->all())->toEqualCanonicalizing([$author->id, $assignee->id]);
});

test('an author who turned issue_created off does not watch their own issue', function () {
    $project = autoWatchProject();
    $author = autoWatchUser($project, ['auto_watch_on' => []]);

    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $author);

    expect($issue->watchers()->count())->toBe(0);
});

test('an assignee only watches when issue_assigned_to_me is on', function () {
    $project = autoWatchProject();
    $author = autoWatchUser($project);
    $optedOut = autoWatchUser($project, ['auto_watch_on' => ['issue_created']]);
    $optedIn = autoWatchUser($project);

    $first = app(IssueService::class)->create(autoWatchAttributes($project, ['assigned_to_id' => $optedOut->id]), $author);
    expect($first->watchers()->where('user_id', $optedOut->id)->exists())->toBeFalse();

    app(IssueService::class)->update($first, ['assigned_to_id' => $optedIn->id], $author);
    expect($first->fresh()->watchers()->where('user_id', $optedIn->id)->exists())->toBeTrue();
});

test('commenting or changing an issue watches it only for users who chose issue_contributed_to', function () {
    $project = autoWatchProject();
    $creator = autoWatchUser($project);
    $contributor = autoWatchUser($project, ['auto_watch_on' => ['issue_contributed_to']]);
    $bystander = autoWatchUser($project, ['auto_watch_on' => []]);
    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $creator);

    app(IssueService::class)->update($issue, [], $bystander, 'a comment');
    expect($issue->watchers()->where('user_id', $bystander->id)->exists())->toBeFalse();

    app(IssueService::class)->update($issue->fresh(), ['subject' => 'Changed'], $contributor);
    expect($issue->watchers()->where('user_id', $contributor->id)->exists())->toBeTrue();
});

test('a comment posted from the issue page counts as a contribution', function () {
    $project = autoWatchProject();
    $creator = autoWatchUser($project);
    $commenter = autoWatchUser($project, ['auto_watch_on' => ['issue_contributed_to']]);
    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $creator);

    Livewire::actingAs($commenter)->test('issues.show', ['project' => $project, 'issue' => $issue])->set('comment', 'Thoughts')->call('addComment');

    expect($issue->watchers()->where('user_id', $commenter->id)->exists())->toBeTrue();
});

test('an update that changes nothing and says nothing adds no watcher', function () {
    $project = autoWatchProject();
    $creator = autoWatchUser($project);
    $contributor = autoWatchUser($project, ['auto_watch_on' => ['issue_contributed_to']]);
    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $creator);

    app(IssueService::class)->update($issue, [], $contributor);

    expect($issue->watchers()->where('user_id', $contributor->id)->exists())->toBeFalse();
});

test('a locked user is never made a watcher', function () {
    $project = autoWatchProject();
    $author = autoWatchUser($project);
    $author->forceFill(['status' => UserStatus::Locked])->save();

    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $author->fresh());

    expect($issue->watchers()->count())->toBe(0);
});

test('the site-wide default reaches accounts that never chose', function () {
    App\Models\Setting::set('default_users_auto_watch_on', ['issue_contributed_to']);
    $project = autoWatchProject();
    $author = autoWatchUser($project);

    $issue = app(IssueService::class)->create(autoWatchAttributes($project), $author);

    expect($issue->watchers()->count())->toBe(0);
});
