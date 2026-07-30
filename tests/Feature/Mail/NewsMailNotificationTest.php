<?php

use App\Enums\MailNotificationOption;
use App\Enums\RoleBuiltin;
use App\Mail\NewsNotificationMail;
use App\Models\Member;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NewsNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function notifiableNewsMember(Project $project, MailNotificationOption $preference, array $permissions = ['view_news', 'comment_news']): User
{
    $user = User::factory()->create(['mail_notification' => $preference]);
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function enableNewsNotifications(): void
{
    Setting::set('notified_events', ['news_added', 'news_comment_added']);
}

function createNewsViaForm(Project $project, User $author, string $title = 'Announcement'): News
{
    Livewire::actingAs($author)
        ->test('news.form', ['project' => $project])
        ->set('title', $title)
        ->set('description', 'body')
        ->call('save');

    return News::where('project_id', $project->id)->where('title', $title)->firstOrFail();
}

test('news notifications are off by default, matching Redmine\'s own notified_events default', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);

    createNewsViaForm($project, $author);

    Notification::assertNothingSent();
});

test('creating news notifies project members regardless of tier, once news_added is enabled', function () {
    // Unlike Issue/Wiki, News notifies EVERY tier except none — no
    // isMember/isWatcher distinction for the base 'added' mail, matching
    // Redmine's User#notify_about?(News) returning true unconditionally
    // once membership + view_news are established.
    enableNewsNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::OnlyMyEvents, ['manage_news', 'view_news']);
    $onlyMyEventsMember = notifiableNewsMember($project, MailNotificationOption::OnlyMyEvents);
    $onlyAssignedMember = notifiableNewsMember($project, MailNotificationOption::OnlyAssigned);

    $news = createNewsViaForm($project, $author);

    Notification::assertSentTo($onlyMyEventsMember, NewsNotification::class, fn (NewsNotification $n) => $n->news->is($news) && $n->eventType === 'added');
    Notification::assertSentTo($onlyAssignedMember, NewsNotification::class);
});

test('a member whose preference is none is never notified', function () {
    enableNewsNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $silent = notifiableNewsMember($project, MailNotificationOption::None);

    createNewsViaForm($project, $author);

    Notification::assertNotSentTo($silent, NewsNotification::class);
});

test('the author is auto-watched and notified of their own news unless no_self_notified is disabled', function () {
    enableNewsNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);

    $news = createNewsViaForm($project, $author);
    expect($news->watchers()->where('user_id', $author->id)->exists())->toBeTrue();
    Notification::assertNotSentTo($author, NewsNotification::class);

    $author->update(['no_self_notified' => false]);
    Notification::fake();

    createNewsViaForm($project, $author, 'Second Announcement');
    Notification::assertSentTo($author, NewsNotification::class);
});

test('editing an existing news item does not send a mail (Redmine has no news-updated notification)', function () {
    enableNewsNotifications();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $bystander = notifiableNewsMember($project, MailNotificationOption::All);

    $news = createNewsViaForm($project, $author);

    Notification::fake();
    Livewire::actingAs($author)
        ->test('news.form', ['project' => $project, 'news' => $news])
        ->set('title', 'Edited title')
        ->call('save');

    Notification::assertNothingSent();
});

test('posting a comment notifies project members and also a non-member watcher', function () {
    enableNewsNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $member = notifiableNewsMember($project, MailNotificationOption::OnlyMyEvents);
    $news = createNewsViaForm($project, $author);

    // A non-member can still watch a visible News item — Redmine's
    // comment-added recipients are `news.notified_users | news.notified_watchers`,
    // a union that reaches beyond project membership. Requires the
    // built-in Non-member role to actually grant view_news, or the
    // NewsPolicy::view() filter would exclude them regardless of
    // watching — matches Redmine's own visible? check on notified_watchers.
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_news']]);
    $nonMember = User::factory()->create(['mail_notification' => MailNotificationOption::OnlyMyEvents]);
    $news->watchers()->create(['user_id' => $nonMember->id]);

    Notification::fake();
    $commenter = notifiableNewsMember($project, MailNotificationOption::OnlyMyEvents);
    Livewire::actingAs($commenter)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('commentContent', 'Nice update!')
        ->call('addComment');

    Notification::assertSentTo($member, NewsNotification::class, fn (NewsNotification $n) => $n->eventType === 'comment_added' && $n->comment?->content === 'Nice update!');
    Notification::assertSentTo($nonMember, NewsNotification::class);
});

test('a member without permission to view the news is not notified', function () {
    enableNewsNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $unprivileged = notifiableNewsMember($project, MailNotificationOption::All, permissions: []);

    createNewsViaForm($project, $author);

    Notification::assertNotSentTo($unprivileged, NewsNotification::class);
});

test('the notification mail subject matches Redmine-style format and links to the news', function () {
    $project = Project::factory()->create(['name' => 'Demo']);
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $recipient = notifiableNewsMember($project, MailNotificationOption::All);

    $news = createNewsViaForm($project, $author, 'Big Launch');
    $news->load('project');

    $mailable = (new NewsNotificationMail($news, 'added', $author))->to($recipient);

    expect($mailable->envelope()->subject)->toContain('Demo')
        ->toContain('Big Launch')
        ->not->toContain('Re:');
    expect($mailable->render())->toContain(route('news.show', [$project, $news]));

    $comment = NewsComment::factory()->for($news)->for($author, 'author')->create(['content' => 'A reply']);
    $commentMailable = new NewsNotificationMail($news, 'comment_added', $author, $comment);

    expect($commentMailable->envelope()->subject)->toContain('Re:');
    expect($commentMailable->render())->toContain('A reply');
});

test('plain_text_mail sends a text-only message', function () {
    Setting::set('plain_text_mail', true);

    $project = Project::factory()->create();
    $author = notifiableNewsMember($project, MailNotificationOption::All, ['manage_news', 'view_news']);
    $news = createNewsViaForm($project, $author);

    $mailable = new NewsNotificationMail($news, 'added', $author);

    expect($mailable->content()->view)->toBeNull()
        ->and($mailable->content()->text)->toBe('mail.news.notification-text');
});
