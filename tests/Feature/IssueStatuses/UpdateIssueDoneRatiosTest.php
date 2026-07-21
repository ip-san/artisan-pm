<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function doneRatioBulkIssue(Project $project, Tracker $tracker, IssueStatus $status, int $doneRatio): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'done_ratio' => $doneRatio,
    ]);
}

test('bulk updating recalculates done_ratio for every issue in a status with a default set', function () {
    Setting::set('issue_done_ratio', 'issue_status');

    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create(['default_done_ratio' => 80]);
    $issueA = doneRatioBulkIssue($project, $tracker, $status, 10);
    $issueB = doneRatioBulkIssue($project, $tracker, $status, 20);

    Livewire::actingAs($admin)->test('issue-statuses.index')->call('updateIssueDoneRatios');

    expect($issueA->fresh()->done_ratio)->toBe(80)
        ->and($issueB->fresh()->done_ratio)->toBe(80);
});

test('an issue whose status has no default_done_ratio is left untouched', function () {
    Setting::set('issue_done_ratio', 'issue_status');

    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create(['default_done_ratio' => null]);
    $issue = doneRatioBulkIssue($project, $tracker, $status, 30);

    Livewire::actingAs($admin)->test('issue-statuses.index')->call('updateIssueDoneRatios');

    expect($issue->fresh()->done_ratio)->toBe(30);
});

test('the bulk update is a no-op when issue_done_ratio is not issue_status', function () {
    Setting::set('issue_done_ratio', 'issue_field');

    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create(['default_done_ratio' => 80]);
    $issue = doneRatioBulkIssue($project, $tracker, $status, 10);

    Livewire::actingAs($admin)->test('issue-statuses.index')->call('updateIssueDoneRatios');

    expect($issue->fresh()->done_ratio)->toBe(10);
});

test('the bulk update button is only shown when issue_done_ratio is issue_status', function () {
    $admin = User::factory()->admin()->create();

    Setting::set('issue_done_ratio', 'issue_field');
    Livewire::actingAs($admin)->test('issue-statuses.index')->assertDontSee('既存課題の進捗率を一括更新');

    Setting::set('issue_done_ratio', 'issue_status');
    Livewire::actingAs($admin)->test('issue-statuses.index')->assertSee('既存課題の進捗率を一括更新');
});

test('a non-admin cannot trigger the bulk update', function () {
    Setting::set('issue_done_ratio', 'issue_status');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create(['default_done_ratio' => 80]);
    $issue = doneRatioBulkIssue($project, $tracker, $status, 10);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('issue-statuses.index')->assertForbidden();

    expect($issue->fresh()->done_ratio)->toBe(10);
});
