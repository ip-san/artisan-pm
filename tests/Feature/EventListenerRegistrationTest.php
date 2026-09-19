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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

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
    'IssueCreated' => [App\Events\IssueCreated::class, [App\Listeners\SendIssueMailNotifications::class, App\Listeners\DispatchWebhooksForIssueEvent::class]],
    'IssueUpdated' => [App\Events\IssueUpdated::class, [App\Listeners\SendIssueMailNotifications::class, App\Listeners\DispatchWebhooksForIssueEvent::class]],
    'IssueDeleted' => [App\Events\IssueDeleted::class, [App\Listeners\DispatchWebhooksForIssueEvent::class]],
    'WikiPageCreated' => [App\Events\WikiPageCreated::class, [App\Listeners\SendWikiPageMailNotifications::class, App\Listeners\DispatchWebhooksForWikiPageEvent::class]],
    'NewsCreated' => [App\Events\NewsCreated::class, [App\Listeners\SendNewsMailNotifications::class, App\Listeners\DispatchWebhooksForNewsEvent::class]],
    'NewsCommentCreated' => [App\Events\NewsCommentCreated::class, [App\Listeners\SendNewsMailNotifications::class]],
    'NewsUpdated' => [App\Events\NewsUpdated::class, [App\Listeners\DispatchWebhooksForNewsEvent::class]],
    'TimeEntryCreated' => [App\Events\TimeEntryCreated::class, [App\Listeners\DispatchWebhooksForTimeEntryEvent::class]],
    'VersionCreated' => [App\Events\VersionCreated::class, [App\Listeners\DispatchWebhooksForVersionEvent::class]],
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
    Illuminate\Support\Facades\Queue::fake();
    $project = Project::factory()->create();
    App\Models\Webhook::factory()->create(['url' => 'https://example.com/once', 'events' => [App\Enums\WebhookEvent::VersionCreated->value, App\Enums\WebhookEvent::NewsCreated->value]]);

    app(App\Services\VersionService::class)->create(['project_id' => $project->id, 'name' => '1.0']);
    App\Events\NewsCreated::dispatch(App\Models\News::factory()->for($project)->create());

    expect(Illuminate\Support\Facades\Queue::pushed(Spatie\WebhookServer\CallWebhookJob::class))->toHaveCount(2);
});
