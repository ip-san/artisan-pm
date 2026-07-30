<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\IssueCreated;
use App\Events\IssueUpdated;
use App\Events\NewsCommentCreated;
use App\Events\NewsCreated;
use App\Events\WikiPageCreated;
use App\Events\WikiPageUpdated;
use App\Listeners\SendIssueMailNotifications;
use App\Listeners\SendNewsMailNotifications;
use App\Listeners\SendWikiPageMailNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registered explicitly rather than relying on event auto-discovery, for
 * the same reason as WebhookServiceProvider: these listeners' handle()
 * methods take a union type that discovery's single-type reflection
 * doesn't resolve to multiple registrations.
 */
final class MailNotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([IssueCreated::class, IssueUpdated::class], SendIssueMailNotifications::class);
        Event::listen([WikiPageCreated::class, WikiPageUpdated::class], SendWikiPageMailNotifications::class);
        Event::listen([NewsCreated::class, NewsCommentCreated::class], SendNewsMailNotifications::class);
    }
}
