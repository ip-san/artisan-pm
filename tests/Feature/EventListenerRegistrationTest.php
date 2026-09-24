<?php

use App\Enums\MailNotificationOption;
use App\Enums\WebhookEvent;
use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueUpdated;
use App\Events\NewsCommentCreated;
use App\Events\NewsCreated;
use App\Events\NewsUpdated;
use App\Events\TimeEntryCreated;
use App\Events\VersionCreated;
use App\Events\WikiPageCreated;
use App\Listeners\DispatchWebhooksForIssueEvent;
use App\Listeners\DispatchWebhooksForNewsEvent;
use App\Listeners\DispatchWebhooksForTimeEntryEvent;
use App\Listeners\DispatchWebhooksForVersionEvent;
use App\Listeners\DispatchWebhooksForWikiPageEvent;
use App\Listeners\SendIssueMailNotifications;
use App\Listeners\SendNewsMailNotifications;
use App\Listeners\SendWikiPageMailNotifications;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Webhook;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use App\Services\VersionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\WebhookServer\CallWebhookJob;

// Webhooks are off by default (Redmine's webhooks_enabled); these tests exercise delivery.
beforeEach(function () {
    Setting::set('webhooks_enabled', true);
});

/**
 * Regression: explicit registration plus Laravel's auto-discovery (which
 * expands a union-typed handle()) registered every listener twice, so each
 * issue, wiki and news mail — and each webhook — was sent twice.
 */
test('every domain event has exactly one registration per listener class', function (string $event, array $listenerClasses) {
    $registered = collect(Event::getRawListeners()[$event] ?? [])
        ->map(fn ($listener) => is_string($listener) ? $listener : (is_array($listener) ? ($listener[0] ?? '') : 'closure'))
        ->map(fn (string $listener) => explode('@', $listener)[0])
        ->countBy();

    foreach ($listenerClasses as $class) {
        expect($registered[$class] ?? 0)->toBe(1, "{$class} for {$event}");
    }
})->with([
    'IssueCreated' => [IssueCreated::class, [SendIssueMailNotifications::class, DispatchWebhooksForIssueEvent::class]],
    'IssueUpdated' => [IssueUpdated::class, [SendIssueMailNotifications::class, DispatchWebhooksForIssueEvent::class]],
    'IssueDeleted' => [IssueDeleted::class, [DispatchWebhooksForIssueEvent::class]],
    'WikiPageCreated' => [WikiPageCreated::class, [SendWikiPageMailNotifications::class, DispatchWebhooksForWikiPageEvent::class]],
    'NewsCreated' => [NewsCreated::class, [SendNewsMailNotifications::class, DispatchWebhooksForNewsEvent::class]],
    'NewsCommentCreated' => [NewsCommentCreated::class, [SendNewsMailNotifications::class]],
    'NewsUpdated' => [NewsUpdated::class, [DispatchWebhooksForNewsEvent::class]],
    'TimeEntryCreated' => [TimeEntryCreated::class, [DispatchWebhooksForTimeEntryEvent::class]],
    'VersionCreated' => [VersionCreated::class, [DispatchWebhooksForVersionEvent::class]],
]);

test('creating an issue sends each recipient exactly one mail', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $author = User::factory()->create(['mail_notification' => MailNotificationOption::OnlyMyEvents]);
    $member = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    foreach ([$author, $member] as $user) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    }

    app(IssueService::class)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'project_id' => $project->id,
        'subject' => 'Once only',
    ], $author);

    expect(Notification::sent($member, IssueNotification::class))->toHaveCount(1);
});

test('one webhook subscription produces exactly one call per event', function () {
    Queue::fake();
    $project = Project::factory()->create();
    Webhook::factory()->create(['url' => 'https://example.com/once', 'events' => [WebhookEvent::VersionCreated->value, WebhookEvent::NewsCreated->value]]);

    app(VersionService::class)->create(['project_id' => $project->id, 'name' => '1.0']);
    NewsCreated::dispatch(News::factory()->for($project)->create());

    expect(Queue::pushed(CallWebhookJob::class))->toHaveCount(2);
});
