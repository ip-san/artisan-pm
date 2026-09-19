<?php

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
function menuExtrasSetup(array $permissions = ['view_issues', 'add_issues', 'edit_issues', 'manage_subtasks', 'log_time']): array
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

function menuExtrasIssue(Project $project, Tracker $tracker, string $subject = 'Menu issue'): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'subject' => $subject,
        'status_id' => IssueStatus::query()->value('id'), 'priority_id' => Enumeration::query()->value('id'),
    ]);
}

test('the watch entry starts watching every selected issue, then stops when all are watched', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup();
    $a = menuExtrasIssue($project, $tracker, 'A');
    $b = menuExtrasIssue($project, $tracker, 'B');

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $a->id, (string) $b->id]);
    $list->call('contextToggleWatch');
    expect($a->watchers()->where('user_id', $user->id)->exists())->toBeTrue()->and($b->watchers()->where('user_id', $user->id)->exists())->toBeTrue();

    $list->call('contextToggleWatch');
    expect($a->watchers()->count())->toBe(0)->and($b->watchers()->count())->toBe(0);
});

test('with a mix of watched and unwatched issues the entry watches them all', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup();
    $a = menuExtrasIssue($project, $tracker, 'A');
    $b = menuExtrasIssue($project, $tracker, 'B');
    $a->watchers()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $a->id, (string) $b->id])->call('contextToggleWatch');

    expect($a->watchers()->where('user_id', $user->id)->count())->toBe(1)->and($b->watchers()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('toggling watch leaves other users\' watches alone', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup();
    $issue = menuExtrasIssue($project, $tracker);
    $other = User::factory()->create();
    $issue->watchers()->create(['user_id' => $other->id]);
    $issue->watchers()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id])->call('contextToggleWatch');

    expect($issue->watchers()->pluck('user_id')->all())->toBe([$other->id]);
});

test('a single selection offers subtask, log time and copy-URL entries when permitted', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup();
    $issue = menuExtrasIssue($project, $tracker);

    $menu = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->call('openContextMenu', $issue->id);

    $menu->assertSee(route('issues.create', $project).'?parent_id='.$issue->id, false)
        ->assertSee(route('time-entries.create', $project).'?issue_id='.$issue->id, false)
        ->assertSee('URLをコピー');
});

test('those entries are hidden without the permissions and for a multiple selection', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup(['view_issues', 'edit_issues']);
    $a = menuExtrasIssue($project, $tracker, 'A');
    $b = menuExtrasIssue($project, $tracker, 'B');

    $menu = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->call('openContextMenu', $a->id);
    $menu->assertDontSee('?parent_id=', false)->assertDontSee('?issue_id=', false)->assertSee('URLをコピー');

    ['project' => $rich, 'user' => $richUser, 'tracker' => $richTracker] = menuExtrasSetup();
    $x = menuExtrasIssue($rich, $richTracker, 'X');
    $y = menuExtrasIssue($rich, $richTracker, 'Y');
    Livewire::actingAs($richUser)->test('issues.index', ['project' => $rich])->set('selected', [(string) $x->id, (string) $y->id])->assertDontSee('URLをコピー');
});

test('the new issue form starts as a subtask when given a parent_id, for a permitted user only', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = menuExtrasSetup();
    $parent = menuExtrasIssue($project, $tracker, 'Parent');

    Livewire::withQueryParams(['parent_id' => $parent->id])->actingAs($user)->test('issues.form', ['project' => $project])->assertSet('parent_id', $parent->id);

    ['project' => $other, 'user' => $noSubtasks, 'tracker' => $otherTracker] = menuExtrasSetup(['view_issues', 'add_issues']);
    $otherParent = menuExtrasIssue($other, $otherTracker, 'Parent');
    Livewire::withQueryParams(['parent_id' => $otherParent->id])->actingAs($noSubtasks)->test('issues.form', ['project' => $other])->assertSet('parent_id', null);
});

test('a parent_id from another project is ignored by the form', function () {
    ['project' => $project, 'user' => $user] = menuExtrasSetup();
    ['project' => $elsewhere, 'tracker' => $elsewhereTracker] = menuExtrasSetup();
    $foreign = menuExtrasIssue($elsewhere, $elsewhereTracker, 'Foreign');

    Livewire::withQueryParams(['parent_id' => $foreign->id])->actingAs($user)->test('issues.form', ['project' => $project])->assertSet('parent_id', null);
});
