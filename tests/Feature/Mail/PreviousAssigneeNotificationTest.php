<?php

use App\Enums\IssueVisibility;
use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Support\Facades\Notification;

function previousAssigneeMember(Project $project, MailNotificationOption $preference, IssueVisibility $visibility = IssueVisibility::All): User
{
    $user = User::factory()->create(['mail_notification' => $preference]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create([
        'permissions' => ['view_issues', 'edit_issues'],
        'assignable' => true,
        'issues_visibility' => $visibility,
    ]));

    return $user;
}

function previousAssigneeIssue(Project $project, User $author, array $attributes = []): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $author->id,
        ...$attributes,
    ]);
}

test('reassigning an issue notifies the previous assignee under only_assigned and only_my_events', function (MailNotificationOption $preference) {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = previousAssigneeMember($project, MailNotificationOption::None);
    $previous = previousAssigneeMember($project, $preference);
    $next = previousAssigneeMember($project, MailNotificationOption::OnlyAssigned);
    $issue = previousAssigneeIssue($project, $actor, ['assigned_to_id' => $previous->id]);

    app(IssueService::class)->update($issue, ['assigned_to_id' => $next->id], $actor);

    Notification::assertSentTo($previous, IssueNotification::class);
    Notification::assertSentTo($next, IssueNotification::class);
})->with([
    'only_assigned' => MailNotificationOption::OnlyAssigned,
    'only_my_events' => MailNotificationOption::OnlyMyEvents,
    'selected' => MailNotificationOption::Selected,
]);

test('the previous assignee is only involved in the update that changed the assignee', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = previousAssigneeMember($project, MailNotificationOption::None);
    $previous = previousAssigneeMember($project, MailNotificationOption::OnlyAssigned);
    $next = previousAssigneeMember($project, MailNotificationOption::None);
    $issue = previousAssigneeIssue($project, $actor, ['assigned_to_id' => $previous->id]);

    app(IssueService::class)->update($issue, ['assigned_to_id' => $next->id], $actor);
    Notification::fake();

    app(IssueService::class)->update($issue->fresh(), ['subject' => 'Renamed'], $actor);

    Notification::assertNotSentTo($previous, IssueNotification::class);
});

test('a previous assignee who opted out is not notified', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = previousAssigneeMember($project, MailNotificationOption::None);
    $previous = previousAssigneeMember($project, MailNotificationOption::None);
    $issue = previousAssigneeIssue($project, $actor, ['assigned_to_id' => $previous->id]);

    app(IssueService::class)->update($issue, ['assigned_to_id' => null], $actor);

    Notification::assertNotSentTo($previous, IssueNotification::class);
});

test('a previous assignee who can no longer see the issue is not notified', function () {
    Notification::fake();
    $project = Project::factory()->create(['is_public' => false]);
    $actor = previousAssigneeMember($project, MailNotificationOption::None);
    // "own" visibility: they saw the issue only while it was assigned to them.
    $previous = previousAssigneeMember($project, MailNotificationOption::OnlyAssigned, IssueVisibility::Own);
    $next = previousAssigneeMember($project, MailNotificationOption::None);
    $issue = previousAssigneeIssue($project, $actor, ['assigned_to_id' => $previous->id]);

    app(IssueService::class)->update($issue, ['assigned_to_id' => $next->id], $actor);

    Notification::assertNotSentTo($previous, IssueNotification::class);
});

test('a previous group assignee expands to its members, each by their own setting and visibility', function () {
    Notification::fake();
    Setting::set('issue_group_assignment', true);
    $project = Project::factory()->create(['is_public' => false]);
    $actor = previousAssigneeMember($project, MailNotificationOption::None);
    $assignedMember = previousAssigneeMember($project, MailNotificationOption::OnlyAssigned);
    $optedOut = previousAssigneeMember($project, MailNotificationOption::None);
    $outsider = User::factory()->create(['mail_notification' => MailNotificationOption::OnlyAssigned]);
    $group = Group::factory()->create();
    $group->users()->attach([$assignedMember->id, $optedOut->id, $outsider->id]);
    $next = previousAssigneeMember($project, MailNotificationOption::None);
    $issue = previousAssigneeIssue($project, $actor, ['assigned_to_group_id' => $group->id]);

    app(IssueService::class)->update($issue, ['assigned_to_id' => $next->id, 'assigned_to_group_id' => null], $actor);

    Notification::assertSentTo($assignedMember, IssueNotification::class);
    Notification::assertNotSentTo($optedOut, IssueNotification::class);
    // Not a member of the private project, so the issue is not visible to them.
    Notification::assertNotSentTo($outsider, IssueNotification::class);
});
