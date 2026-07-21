<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function logTimeFormMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function logTimeFormIssue(Project $project): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a member with log_time can log time while editing an issue', function () {
    $project = Project::factory()->create();
    $user = logTimeFormMember($project, ['view_issues', 'edit_issues', 'log_time']);
    $issue = logTimeFormIssue($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', $issue->subject)
        ->set('logTimeHours', '2.5')
        ->set('logTimeActivityId', $activity->id)
        ->set('logTimeComments', 'Investigated the bug')
        ->call('save');

    $entry = TimeEntry::query()->where('issue_id', $issue->id)->sole();

    expect((float) $entry->hours)->toBe(2.5)
        ->and($entry->activity_id)->toBe($activity->id)
        ->and($entry->comments)->toBe('Investigated the bug')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->project_id)->toBe($project->id);
});

test('the log time fieldset is not offered without log_time', function () {
    $project = Project::factory()->create();
    $user = logTimeFormMember($project, ['view_issues', 'edit_issues']);
    $issue = logTimeFormIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertDontSee('工数を記録');
});

test('leaving the hours field blank does not create a time entry', function () {
    $project = Project::factory()->create();
    $user = logTimeFormMember($project, ['view_issues', 'edit_issues', 'log_time']);
    $issue = logTimeFormIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', $issue->subject)
        ->call('save');

    expect(TimeEntry::query()->where('issue_id', $issue->id)->exists())->toBeFalse();
});

test('logging time without selecting an activity fails validation', function () {
    $project = Project::factory()->create();
    $user = logTimeFormMember($project, ['view_issues', 'edit_issues', 'log_time']);
    $issue = logTimeFormIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('subject', $issue->subject)
        ->set('logTimeHours', '1')
        ->set('logTimeActivityId', null)
        ->call('save')
        ->assertHasErrors('logTimeActivityId');

    expect(TimeEntry::query()->where('issue_id', $issue->id)->exists())->toBeFalse();
});
