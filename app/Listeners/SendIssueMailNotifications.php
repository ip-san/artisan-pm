<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IssueCreated;
use App\Events\IssueUpdated;
use App\Notifications\IssueNotification;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

final class SendIssueMailNotifications
{
    public function handle(IssueCreated|IssueUpdated $event): void
    {
        $isCreated = $event instanceof IssueCreated;

        $actor = $isCreated ? $event->issue->author : $event->actor;
        $eventKey = $isCreated ? 'issue_added' : 'issue_updated';
        $journal = $isCreated ? null : $event->journal;

        $recipients = NotificationRecipients::forIssue($event->issue, $eventKey, $actor, $event->mentionedLogins);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new IssueNotification($event->issue, $isCreated ? 'created' : 'updated', $actor, $journal),
        );
    }
}
