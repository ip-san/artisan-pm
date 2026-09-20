<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IssueCreated;
use App\Events\IssueJournalRecorded;
use App\Events\IssueUpdated;
use App\Notifications\IssueNotification;
use App\Support\Mail\NotificationRecipients;
use App\Support\Mail\MailSuppression;
use Illuminate\Support\Facades\Notification;

final class SendIssueMailNotifications
{
    public function handle(IssueCreated|IssueUpdated|IssueJournalRecorded $event): void
    {
        if (MailSuppression::active()) {
            return;
        }

        $isCreated = $event instanceof IssueCreated;

        $actor = $isCreated ? $event->issue->author : $event->actor;
        $journal = $isCreated ? null : $event->journal;
        $eventKey = $isCreated ? 'issue_added' : self::updateEventKey($journal);
        $mentionedLogins = $event instanceof IssueJournalRecorded ? [] : $event->mentionedLogins;

        $recipients = NotificationRecipients::forIssue($event->issue, $eventKey, $actor, $mentionedLogins);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new IssueNotification($event->issue, $isCreated ? 'created' : 'updated', $actor, $journal),
        );
    }

    /**
     * Redmine's Journal#send_notification: an update mails when
     * `issue_updated` is on, or — for one that carries a comment — when only
     * `issue_note_added` is on.
     */
    private static function updateEventKey(?\App\Models\Journal $journal): string
    {
        $events = NotificationRecipients::notifiedEventKeys();

        if (filled($journal?->notes) && ! in_array('issue_updated', $events, true) && in_array('issue_note_added', $events, true)) {
            return 'issue_note_added';
        }

        return 'issue_updated';
    }
}
