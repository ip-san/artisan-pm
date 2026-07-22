<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueUpdated;
use App\Events\WikiPageCreated;
use App\Events\WikiPageDeleted;
use App\Events\WikiPageUpdated;
use App\Listeners\DispatchWebhooksForIssueEvent;
use App\Listeners\DispatchWebhooksForWikiPageEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registered explicitly rather than relying on Laravel's event
 * auto-discovery, since each listener's handle() takes a union type
 * (e.g. IssueCreated|IssueUpdated|IssueDeleted) that discovery's
 * single-type reflection doesn't resolve to multiple registrations.
 */
final class WebhookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([IssueCreated::class, IssueUpdated::class, IssueDeleted::class], DispatchWebhooksForIssueEvent::class);
        Event::listen([WikiPageCreated::class, WikiPageUpdated::class, WikiPageDeleted::class], DispatchWebhooksForWikiPageEvent::class);
    }
}
