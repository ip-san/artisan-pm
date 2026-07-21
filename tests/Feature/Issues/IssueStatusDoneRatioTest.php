<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Services\IssueService;
use Livewire\Livewire;

function doneRatioIssue(Project $project, Tracker $tracker, IssueStatus $status): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'done_ratio' => 10,
    ]);
}

test('an admin can set a status default done ratio', function () {
    $admin = User::factory()->admin()->create();
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($admin)
        ->test('issue-statuses.form', ['issueStatus' => $status])
        ->set('default_done_ratio', 75)
        ->call('save');

    expect($status->fresh()->default_done_ratio)->toBe(75);
});

test('transitioning to a status with a default done ratio overrides it when the setting is issue_status', function () {
    Setting::set('issue_done_ratio', 'issue_status');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $open = IssueStatus::factory()->create();
    $done = IssueStatus::factory()->create(['default_done_ratio' => 80]);
    $issue = doneRatioIssue($project, $tracker, $open);
    $actor = User::factory()->create();

    app(IssueService::class)->update($issue, ['status_id' => $done->id], $actor);

    expect($issue->fresh()->done_ratio)->toBe(80);
});

test('the done ratio is left untouched when the setting is issue_field', function () {
    Setting::set('issue_done_ratio', 'issue_field');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $open = IssueStatus::factory()->create();
    $done = IssueStatus::factory()->create(['default_done_ratio' => 80]);
    $issue = doneRatioIssue($project, $tracker, $open);
    $actor = User::factory()->create();

    app(IssueService::class)->update($issue, ['status_id' => $done->id], $actor);

    expect($issue->fresh()->done_ratio)->toBe(10);
});

test('a manual done_ratio submission is overridden when moving to a status with a default', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Setting::set('issue_done_ratio', 'issue_status');
    $open = IssueStatus::factory()->create();
    $done = IssueStatus::factory()->create(['default_done_ratio' => 90]);
    $issue = doneRatioIssue($project, $tracker, $open);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    WorkflowTransition::create([
        'tracker_id' => $tracker->id,
        'role_id' => $role->id,
        'old_status_id' => $open->id,
        'new_status_id' => $done->id,
    ]);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('status_id', $done->id)
        ->set('done_ratio', 30)
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->done_ratio)->toBe(90);
});

test('the done ratio slider is disabled on the form when the setting is issue_status', function () {
    Setting::set('issue_done_ratio', 'issue_status');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = doneRatioIssue($project, $tracker, IssueStatus::factory()->create());

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSee('ステータスから自動算出されます');
});
