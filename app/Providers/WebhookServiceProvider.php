<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueUpdated;
use App\Events\NewsCreated;
use App\Events\NewsDeleted;
use App\Events\NewsUpdated;
use App\Events\TimeEntryCreated;
use App\Events\TimeEntryDeleted;
use App\Events\TimeEntryUpdated;
use App\Events\VersionCreated;
use App\Events\VersionDeleted;
use App\Events\VersionUpdated;
use App\Events\WikiPageCreated;
use App\Events\WikiPageDeleted;
use App\Events\WikiPageUpdated;
use App\Listeners\DispatchWebhooksForIssueEvent;
use App\Listeners\DispatchWebhooksForNewsEvent;
use App\Listeners\DispatchWebhooksForTimeEntryEvent;
use App\Listeners\DispatchWebhooksForVersionEvent;
use App\Listeners\DispatchWebhooksForWikiPageEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registered explicitly, with event auto-discovery switched off in
 * bootstrap/app.php. (The earlier belief that discovery cannot resolve a
 * union-typed handle() was wrong — it registers one listener per event
 * type — so leaving both on registered every listener twice.)
 */
final class WebhookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([IssueCreated::class, IssueUpdated::class, IssueDeleted::class], DispatchWebhooksForIssueEvent::class);
        Event::listen([WikiPageCreated::class, WikiPageUpdated::class, WikiPageDeleted::class], DispatchWebhooksForWikiPageEvent::class);
        Event::listen([TimeEntryCreated::class, TimeEntryUpdated::class, TimeEntryDeleted::class], DispatchWebhooksForTimeEntryEvent::class);
        Event::listen([VersionCreated::class, VersionUpdated::class, VersionDeleted::class], DispatchWebhooksForVersionEvent::class);
        Event::listen([NewsCreated::class, NewsUpdated::class, NewsDeleted::class], DispatchWebhooksForNewsEvent::class);
    }
}
