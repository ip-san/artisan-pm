<?php

use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int}
 */
function mentionIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
}

function mentionableUser(Project $project, string $login, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create(['login' => $login]);
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('mentioning a project member notifies them even though they otherwise wouldn\'t be (only_my_events, not watching)', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $mentioned = User::factory()->create(['login' => 'mentioned-user', 'mail_notification' => MailNotificationOption::OnlyMyEvents]);
    Member::factory()->for($project)->for($mentioned)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    $issue = app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @mentioned-user please look',
    ], $author);

    Notification::assertSentTo($mentioned, IssueNotification::class, fn (IssueNotification $n) => $n->issue->is($issue));
});

test('mentioning a user with no project access at all does not notify them', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $outsider = User::factory()->create(['login' => 'outsider']);

    app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @outsider',
    ], $author);

    Notification::assertNotSentTo($outsider, IssueNotification::class);
});

test('mentioning a user whose mail_notification is none does not notify them', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $silent = User::factory()->create(['login' => 'silent-user', 'mail_notification' => MailNotificationOption::None]);
    Member::factory()->for($project)->for($silent)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @silent-user',
    ], $author);

    Notification::assertNotSentTo($silent, IssueNotification::class);
});

test('adding a comment that mentions a user notifies them', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $mentioned = mentionableUser($project, 'commented-mention');

    $issue = app(IssueService::class)->create([...mentionIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);
    Notification::fake();

    app(IssueService::class)->update($issue, [], $author, 'cc @commented-mention take a look');

    Notification::assertSentTo($mentioned, IssueNotification::class);
});

test('editing the description without adding a new mention does not re-notify the already-mentioned user', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $mentioned = mentionableUser($project, 'already-mentioned');

    $issue = app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @already-mentioned',
    ], $author);
    Notification::fake();

    app(IssueService::class)->update($issue, ['description' => 'cc @already-mentioned, extra context added'], $author);

    Notification::assertNotSentTo($mentioned, IssueNotification::class);
});

test('a fresh mention added on top of an unchanged prior mention still notifies only the new one', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'author');
    $already = mentionableUser($project, 'already-there');
    $fresh = mentionableUser($project, 'fresh-mention');

    $issue = app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @already-there',
    ], $author);
    Notification::fake();

    app(IssueService::class)->update($issue, ['description' => 'cc @already-there and now also @fresh-mention'], $author);

    Notification::assertSentTo($fresh, IssueNotification::class);
    Notification::assertNotSentTo($already, IssueNotification::class);
});

test('mentioning yourself as the author does not notify you when no_self_notified is enabled', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableUser($project, 'self-mentioner');

    app(IssueService::class)->create([
        ...mentionIssueDefaults(), 'project_id' => $project->id,
        'subject' => 'New issue', 'description' => 'cc @self-mentioner (myself)',
    ], $author);

    Notification::assertNotSentTo($author, IssueNotification::class);
});

test('forMentionedUsers resolves nothing for an empty login list without querying', function () {
    $author = User::factory()->create();

    expect(NotificationRecipients::forMentionedUsers([], $author))->toBeEmpty();
});
