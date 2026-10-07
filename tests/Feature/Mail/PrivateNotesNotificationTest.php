<?php

use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Support\Facades\Notification;

function privateNotesWatcher(Project $project, Issue $issue, array $permissions): User
{
    $user = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    $issue->watchers()->create(['user_id' => $user->id]);

    return $user;
}

test('a private note is mailed only to watchers who may read private notes', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = User::factory()->create();
    $insider = privateNotesWatcher($project, $issue, ['view_issues', 'view_private_notes']);
    $outsider = privateNotesWatcher($project, $issue, ['view_issues']);

    app(IssueService::class)->update($issue, [], $author, 'Customer pays net 90, do not mention it.', commentIsPrivate: true);

    Notification::assertSentTo($insider, IssueNotification::class);
    Notification::assertNotSentTo($outsider, IssueNotification::class);

    // A public note still reaches both.
    app(IssueService::class)->update($issue->fresh(), [], $author, 'Scheduled for next sprint.');

    Notification::assertSentTo($outsider, IssueNotification::class);
});

test('the author is notified by their own setting even when not a member (A17-03a)', function () {
    Notification::fake();
    $project = Project::factory()->create(['is_public' => true]);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create(['mail_notification' => MailNotificationOption::OnlyMyEvents])->id,
    ]);
    // A non-member reads a public project's issues through the builtin Non member role.
    Role::factory()->create(['name' => 'Non member', 'builtin' => \App\Enums\RoleBuiltin::NonMember->value, 'permissions' => ['view_issues']]);

    app(IssueService::class)->update($issue, [], User::factory()->create(), 'A reply to the reporter.');

    Notification::assertSentTo($issue->author, IssueNotification::class);
});

test('a watcher is notified whatever their tier, unless it is none (A17-03b)', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $onlyAssigned = privateNotesWatcher($project, $issue, ['view_issues']);
    $onlyAssigned->update(['mail_notification' => MailNotificationOption::OnlyAssigned]);
    $none = privateNotesWatcher($project, $issue, ['view_issues']);
    $none->update(['mail_notification' => MailNotificationOption::None]);

    app(IssueService::class)->update($issue, [], User::factory()->create(), 'Progress update.');

    Notification::assertSentTo($onlyAssigned, IssueNotification::class);
    Notification::assertNotSentTo($none, IssueNotification::class);
});

test('a private note saved with other changes is mailed as its own journal to those who may read it (A17-03c)', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $insider = privateNotesWatcher($project, $issue, ['view_issues', 'view_private_notes']);
    $outsider = privateNotesWatcher($project, $issue, ['view_issues']);

    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], User::factory()->create(), 'Internal: refund approved.', commentIsPrivate: true);

    Notification::assertSentToTimes($insider, IssueNotification::class, 2);
    Notification::assertSentToTimes($outsider, IssueNotification::class, 1);
    Notification::assertSentTo($outsider, IssueNotification::class, fn (IssueNotification $n) => ! $n->journal?->private_notes);
});
