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
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 * @return array{project: Project, user: User, tracker: Tracker}
 */
function formWatchersSetup(array $permissions = ['view_issues', 'add_issues', 'add_issue_watchers']): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return compact('project', 'user', 'tracker');
}

function formWatchersMember(Project $project, string $name = 'Watcher', UserStatus $status = UserStatus::Active): User
{
    $user = User::factory()->create(['name' => $name, 'status' => $status]);
    Member::factory()->for($project)->for($user)->create();

    return $user;
}

function formWatchersFill(\Livewire\Features\SupportTesting\Testable $form, Tracker $tracker): \Livewire\Features\SupportTesting\Testable
{
    return $form->set('subject', 'With watchers')->set('tracker_id', $tracker->id)->set('priority_id', Enumeration::query()->value('id'));
}

test('the new issue form offers members as watchers and saves the picked ones', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = formWatchersSetup();
    $alice = formWatchersMember($project, 'Alice');
    $bob = formWatchersMember($project, 'Bob');

    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertSee('Alice')->assertSee('data-watcher-picker', false);
    formWatchersFill($form, $tracker)->set('watcher_user_ids', [(string) $alice->id])->call('save')->assertHasNoErrors();

    $issue = Issue::where('subject', 'With watchers')->firstOrFail();
    $watchers = $issue->watchers()->pluck('user_id')->all();
    expect($watchers)->toContain($alice->id)->not->toContain($bob->id);
});

test('without add_issue_watchers the picker is hidden and any submitted ids are ignored', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = formWatchersSetup(['view_issues', 'add_issues']);
    $alice = formWatchersMember($project, 'Alice');

    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertDontSee('data-watcher-picker', false);
    formWatchersFill($form, $tracker)->set('watcher_user_ids', [(string) $alice->id])->call('save')->assertHasNoErrors();

    expect(Issue::where('subject', 'With watchers')->firstOrFail()->watchers()->pluck('user_id')->all())->not->toContain($alice->id);
});

test('a non-member or locked user cannot be slipped in as a watcher', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = formWatchersSetup();
    $outsider = User::factory()->create();
    $locked = formWatchersMember($project, 'Locked One', UserStatus::Locked);

    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertDontSee('Locked One');
    formWatchersFill($form, $tracker)->set('watcher_user_ids', [(string) $outsider->id, (string) $locked->id])->call('save')->assertHasNoErrors();

    $ids = Issue::where('subject', 'With watchers')->firstOrFail()->watchers()->pluck('user_id')->all();
    expect($ids)->not->toContain($outsider->id)->not->toContain($locked->id);
});

test('editing an existing issue does not show the new-issue picker', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = formWatchersSetup(['view_issues', 'add_issues', 'edit_issues', 'add_issue_watchers']);
    formWatchersMember($project, 'Alice');
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::query()->value('id'), 'priority_id' => Enumeration::query()->value('id')]);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue])->assertDontSee('data-watcher-picker', false);
});
