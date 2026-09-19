<?php

use App\Enums\MailNotificationOption;
use App\Mail\IssueNotificationMail;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Support\Facades\Notification;

function notifiableMember(Project $project, MailNotificationOption $preference, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create(['mail_notification' => $preference]);
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int}
 */
function mailIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
}

test('creating an issue notifies a member whose preference is all', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableMember($project, MailNotificationOption::All);

    $issue = app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertSentTo($bystander, IssueNotification::class, fn (IssueNotification $n) => $n->issue->is($issue) && $n->eventType === 'created');
});

test('a member whose preference is none is never notified', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $silent = notifiableMember($project, MailNotificationOption::None);

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertNotSentTo($silent, IssueNotification::class);
});

test('a member whose preference is only_my_events is notified only when watching', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $notWatching = notifiableMember($project, MailNotificationOption::OnlyMyEvents);

    $issue = app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    // Neither the acting author (excluded by no_self_notified being on
    // by default) nor a same-tier member who never watched is notified.
    Notification::assertNotSentTo($notWatching, IssueNotification::class);

    $issue->watchers()->create(['user_id' => $notWatching->id]);
    Notification::fake();

    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], $author);

    // Once watching, the same preference tier now includes them.
    Notification::assertSentTo($notWatching, IssueNotification::class);
});

test('a member whose preference is only_assigned is notified only for their own assignment', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $assignee = notifiableMember($project, MailNotificationOption::OnlyAssigned);
    $otherAssignable = notifiableMember($project, MailNotificationOption::OnlyAssigned);

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue', 'assigned_to_id' => $assignee->id], $author);

    Notification::assertSentTo($assignee, IssueNotification::class);
    Notification::assertNotSentTo($otherAssignable, IssueNotification::class);
});

test('the actor is not notified of their own change unless their no_self_notified preference is disabled', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);
    Notification::assertNotSentTo($author, IssueNotification::class);

    $author->update(['no_self_notified' => false]);
    Notification::fake();

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Another issue'], $author);
    Notification::assertSentTo($author, IssueNotification::class);
});

test('disabling issue_added in notified_events suppresses the mail entirely', function () {
    Notification::fake();
    Setting::set('notified_events', ['issue_updated']);

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::All);
    $member = notifiableMember($project, MailNotificationOption::All);

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertNothingSent();
});

test('a member without permission to view the issue is not notified', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $unprivileged = notifiableMember($project, MailNotificationOption::All, permissions: []);

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertNotSentTo($unprivileged, IssueNotification::class);
});

test('the notification mail subject matches Redmine\'s format and includes changed attributes', function () {
    $project = Project::factory()->create(['name' => 'Demo']);
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $recipient = notifiableMember($project, MailNotificationOption::All);

    $issue = app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);
    $issue->load('tracker', 'status', 'project');

    $updated = app(IssueService::class)->update($issue, ['subject' => 'Renamed issue'], $author, 'a comment');
    $journal = $updated->journals()->latest('id')->first();

    $mailable = (new IssueNotificationMail($updated, 'updated', $author, $journal))->to($recipient);
    $rendered = $mailable->render();

    expect($mailable->envelope()->subject)->toContain("#{$issue->id}")
        ->toContain('Demo')
        ->toContain('Renamed issue');
    expect($rendered)->toContain('題名')->toContain('a comment');
});

test('a custom-field-only update renders the changed field in the mail', function () {
    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $recipient = notifiableMember($project, MailNotificationOption::All);

    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);

    $issue = app(IssueService::class)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'project_id' => $project->id,
        'subject' => 'New issue',
    ], $author);

    $updated = app(IssueService::class)->update($issue, [], $author, null, [$field->id => 'High']);
    $journal = $updated->journals()->latest('id')->first();

    $rendered = (new IssueNotificationMail($updated, 'updated', $author, $journal))->to($recipient)->render();

    expect($rendered)->toContain('Severity')->toContain('High');
});

test('plain_text_mail sends a text-only message', function () {
    Setting::set('plain_text_mail', true);

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);

    $issue = app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    $mailable = new IssueNotificationMail($issue, 'created', $author);

    expect($mailable->content()->view)->toBeNull()
        ->and($mailable->content()->text)->toBe('mail.issues.notification-text');
});

test('a user who belongs to a member group is notified like a direct member', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $groupUser = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    $group = Group::factory()->create();
    $group->users()->attach($groupUser);
    Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null])->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues']])
    );

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertSentTo($groupUser, IssueNotification::class);
});

test('a group user who cannot view the issue is still not notified, and a non-member group is ignored', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableMember($project, MailNotificationOption::OnlyMyEvents);
    $noViewUser = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    $strangerUser = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    $memberGroup = Group::factory()->create();
    $memberGroup->users()->attach($noViewUser);
    Group::factory()->create()->users()->attach($strangerUser);
    Member::factory()->for($project)->create(['group_id' => $memberGroup->id, 'user_id' => null])->roles()->attach(
        Role::factory()->create(['permissions' => []])
    );

    app(IssueService::class)->create([...mailIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Notification::assertNotSentTo($noViewUser, IssueNotification::class);
    Notification::assertNotSentTo($strangerUser, IssueNotification::class);
});

test('memberUserIds merges direct and group members without duplicates', function () {
    $project = Project::factory()->create();
    $direct = User::factory()->create();
    $both = User::factory()->create();
    $viaGroupOnly = User::factory()->create();
    Member::factory()->for($project)->for($direct)->create();
    Member::factory()->for($project)->for($both)->create();
    $group = Group::factory()->create();
    $group->users()->attach([$both->id, $viaGroupOnly->id]);
    Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null]);

    expect($project->memberUserIds()->sort()->values()->all())->toBe(collect([$direct->id, $both->id, $viaGroupOnly->id])->sort()->values()->all());
});
