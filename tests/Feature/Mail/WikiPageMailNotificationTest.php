<?php

use App\Enums\MailNotificationOption;
use App\Mail\WikiPageNotificationMail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WikiPageNotification;
use App\Services\WikiPageService;
use Illuminate\Support\Facades\Notification;

function notifiableWikiMember(Project $project, MailNotificationOption $preference, array $permissions = ['view_wiki_pages']): User
{
    $user = User::factory()->create(['mail_notification' => $preference]);
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function enableWikiNotifications(): void
{
    Setting::set('notified_events', ['wiki_content_added', 'wiki_content_updated']);
}

test('wiki notifications are off by default, matching Redmine\'s own notified_events default', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableWikiMember($project, MailNotificationOption::All);

    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::assertNothingSent();
});

test('creating a wiki page notifies a member whose preference is all, once wiki_content_added is enabled', function () {
    enableWikiNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableWikiMember($project, MailNotificationOption::All);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::assertSentTo($bystander, WikiPageNotification::class, fn (WikiPageNotification $n) => $n->wikiPage->is($page) && $n->eventType === 'created');
});

test('a member whose preference is none is never notified', function () {
    enableWikiNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $silent = notifiableWikiMember($project, MailNotificationOption::None);

    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::assertNotSentTo($silent, WikiPageNotification::class);
});

test('a member whose preference is only_assigned is not notified unless watching, unlike issues', function () {
    // Redmine's Project#notified_users has no assignee/owner concept for
    // wiki pages — OnlyAssigned/OnlyOwner tiers only get wiki mail via
    // watching, never unconditionally the way they do for issues.
    enableWikiNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $assignedTier = notifiableWikiMember($project, MailNotificationOption::OnlyAssigned);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::assertNotSentTo($assignedTier, WikiPageNotification::class);

    $page->watchers()->create(['user_id' => $assignedTier->id]);
    Notification::fake();

    app(WikiPageService::class)->update($page, [], 'body updated', $author);

    Notification::assertSentTo($assignedTier, WikiPageNotification::class);
});

test('renaming a page without changing its text does not send a mail', function () {
    enableWikiNotifications();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableWikiMember($project, MailNotificationOption::All);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::fake();
    app(WikiPageService::class)->update($page, ['title' => 'Renamed'], 'body', $author);

    Notification::assertNothingSent();
});

test('editing a page\'s text does send a mail', function () {
    enableWikiNotifications();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableWikiMember($project, MailNotificationOption::All);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::fake();
    app(WikiPageService::class)->update($page, [], 'new body', $author);

    Notification::assertSentTo($bystander, WikiPageNotification::class, fn (WikiPageNotification $n) => $n->eventType === 'updated');
});

test('the actor is not notified of their own edit unless their no_self_notified preference is disabled', function () {
    // Unlike IssueService, WikiPageService doesn't auto-watch the author
    // on creation (matches Redmine — WikiContent has no such callback) —
    // so this needs the All tier (isMember-based) to make the author a
    // notification candidate at all; OnlyMyEvents would require watching
    // and never reach the no_self_notified check being tested here.
    enableWikiNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::All);

    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);
    Notification::assertNotSentTo($author, WikiPageNotification::class);

    $author->update(['no_self_notified' => false]);
    Notification::fake();

    app(WikiPageService::class)->create($project, ['title' => 'Another'], 'body', $author);
    Notification::assertSentTo($author, WikiPageNotification::class);
});

test('a member without permission to view the wiki is not notified', function () {
    enableWikiNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $unprivileged = notifiableWikiMember($project, MailNotificationOption::All, permissions: []);

    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::assertNotSentTo($unprivileged, WikiPageNotification::class);
});

test('moving a page to another project without changing its text does not send a mail', function () {
    enableWikiNotifications();

    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::fake();
    app(WikiPageService::class)->moveToProject($page, $target);

    Notification::assertNothingSent();
});

test('a rename that also changes the text still creates a version and sends a mail', function () {
    // WikiPageService::update() computes $textChanged before the
    // transaction (from the page's pre-update currentVersion), while
    // handleRename() runs inside it — this proves the reordering didn't
    // break either the version write or the mail dispatch for the
    // combined case, not just rename-only or text-only individually.
    enableWikiNotifications();

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $bystander = notifiableWikiMember($project, MailNotificationOption::All);
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    Notification::fake();
    $updated = app(WikiPageService::class)->update($page, ['title' => 'Renamed'], 'new body', $author);

    expect($updated->versions()->count())->toBe(2);
    Notification::assertSentTo($bystander, WikiPageNotification::class);
});

test('the notification mail subject matches Redmine-style format and links to the page', function () {
    $project = Project::factory()->create(['name' => 'Demo']);
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);
    $recipient = notifiableWikiMember($project, MailNotificationOption::All);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);
    $page->load('project');

    $mailable = (new WikiPageNotificationMail($page, 'created', $author))->to($recipient);

    expect($mailable->envelope()->subject)->toContain('Demo')
        ->toContain('Home')
        ->toContain('追加されました');
    expect($mailable->render())->toContain(route('wiki.show', [$project, $page]));
});

test('plain_text_mail sends a text-only message', function () {
    Setting::set('plain_text_mail', true);

    $project = Project::factory()->create();
    $author = notifiableWikiMember($project, MailNotificationOption::OnlyMyEvents);

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'body', $author);

    $mailable = new WikiPageNotificationMail($page, 'created', $author);

    expect($mailable->content()->view)->toBeNull()
        ->and($mailable->content()->text)->toBe('mail.wiki-pages.notification-text');
});
