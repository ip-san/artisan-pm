<?php

use App\Enums\MailNotificationOption;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WikiPageNotification;
use App\Services\WikiPageService;
use Illuminate\Support\Facades\Notification;

function enableWikiMentionNotifications(): void
{
    Setting::set('notified_events', ['wiki_content_added', 'wiki_content_updated']);
}

function mentionableWikiUser(Project $project, string $login, array $permissions = ['view_wiki_pages']): User
{
    $user = User::factory()->create(['login' => $login]);
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('mentioning a project member in a new wiki page notifies them even though they otherwise wouldn\'t be', function () {
    enableWikiMentionNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableWikiUser($project, 'wiki-author');
    $mentioned = User::factory()->create(['login' => 'wiki-mentioned', 'mail_notification' => MailNotificationOption::OnlyMyEvents]);
    Member::factory()->for($project)->for($mentioned)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_wiki_pages']]));

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'cc @wiki-mentioned please review', $author);

    Notification::assertSentTo($mentioned, WikiPageNotification::class, fn (WikiPageNotification $n) => $n->wikiPage->is($page));
});

test('mentioning a user with no project access at all does not notify them', function () {
    enableWikiMentionNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableWikiUser($project, 'wiki-author');
    $outsider = User::factory()->create(['login' => 'wiki-outsider']);

    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'cc @wiki-outsider', $author);

    Notification::assertNotSentTo($outsider, WikiPageNotification::class);
});

test('editing a wiki page without adding a new mention does not re-notify the already-mentioned user', function () {
    enableWikiMentionNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableWikiUser($project, 'wiki-author');
    $mentioned = mentionableWikiUser($project, 'wiki-already-mentioned');

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'cc @wiki-already-mentioned', $author);
    Notification::fake();

    app(WikiPageService::class)->update($page, [], 'cc @wiki-already-mentioned, extra context added', $author);

    Notification::assertNotSentTo($mentioned, WikiPageNotification::class);
});

test('a fresh mention added on top of an unchanged prior mention notifies only the new one', function () {
    enableWikiMentionNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableWikiUser($project, 'wiki-author');
    $already = mentionableWikiUser($project, 'wiki-already-there');
    $fresh = mentionableWikiUser($project, 'wiki-fresh-mention');

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'cc @wiki-already-there', $author);
    Notification::fake();

    app(WikiPageService::class)->update($page, [], 'cc @wiki-already-there and now also @wiki-fresh-mention', $author);

    Notification::assertSentTo($fresh, WikiPageNotification::class);
    Notification::assertNotSentTo($already, WikiPageNotification::class);
});

test('a rename that does not change the text carries no mentions, even if the title itself looks like one', function () {
    enableWikiMentionNotifications();
    Notification::fake();

    $project = Project::factory()->create();
    $author = mentionableWikiUser($project, 'wiki-author');
    $mentioned = mentionableWikiUser($project, 'wiki-rename-mention');

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'plain body text', $author);
    Notification::fake();

    app(WikiPageService::class)->update($page, ['title' => 'Renamed @wiki-rename-mention Page'], 'plain body text', $author);

    Notification::assertNotSentTo($mentioned, WikiPageNotification::class);
});
