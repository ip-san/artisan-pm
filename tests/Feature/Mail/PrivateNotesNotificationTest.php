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
