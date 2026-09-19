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
 * Registered explicitly, with event auto-discovery switched off in
 * bootstrap/app.php — see WebhookServiceProvider for why.
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
