<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WikiPageCreated;
use App\Events\WikiPageUpdated;
use App\Notifications\WikiPageNotification;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

final class SendWikiPageMailNotifications
{
    public function handle(WikiPageCreated|WikiPageUpdated $event): void
    {
        // Matches Redmine's WikiContent#after_update only mailing when
        // saved_change_to_text? is true — a rename/move dispatches this
        // event (webhook subscribers want to know either way) but isn't a
        // content edit, so it's not mailed.
        if ($event instanceof WikiPageUpdated && ! $event->textChanged) {
            return;
        }

        $isCreated = $event instanceof WikiPageCreated;
        $eventKey = $isCreated ? 'wiki_content_added' : 'wiki_content_updated';

        // currentVersion is the version that was just written — its
        // author is who made this edit, matching Redmine's
        // wiki_content.author.
        $actor = $event->wikiPage->currentVersion?->author;

        if ($actor === null) {
            return;
        }

        $recipients = NotificationRecipients::forWikiPage($event->wikiPage, $eventKey, $actor, $event->mentionedLogins);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new WikiPageNotification($event->wikiPage, $isCreated ? 'created' : 'updated', $actor),
        );
    }
}
