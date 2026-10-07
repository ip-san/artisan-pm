<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IssueCreated;
use App\Events\IssueJournalRecorded;
use App\Events\IssueUpdated;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Notifications\IssueNotification;
use App\Support\Mail\MailSuppression;
use App\Support\Mail\NotificationRecipients;
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
        $eventKeys = $isCreated ? ['issue_added'] : self::updateEventKeys($journal);
        $mentionedLogins = $event instanceof IssueJournalRecorded ? [] : $event->mentionedLogins;

        $recipients = NotificationRecipients::forIssue($event->issue, $eventKeys, $actor, $mentionedLogins, $journal);

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
     * `issue_updated` is on, OR any of the finer-grained events this
     * particular journal matches is on — a comment (`issue_note_added`), a
     * status/assignee/priority/fixed-version change recorded as a detail
     * (`issue_*_updated`), or an added attachment (`issue_attachment_added`).
     * NotificationRecipients::forIssue() checks this whole candidate set
     * against Setting.notified_events with an OR (array_intersect), so every
     * key that legitimately applies to the journal is returned here — not
     * just the first match.
     *
     * @return array<int, string>
     */
    private static function updateEventKeys(?Journal $journal): array
    {
        $keys = ['issue_updated'];

        if ($journal === null) {
            return $keys;
        }

        if (filled($journal->notes)) {
            $keys[] = 'issue_note_added';
        }

        $details = $journal->details;

        if ($details->contains(fn (JournalDetail $d) => $d->property === 'attr' && $d->prop_key === 'status_id')) {
            $keys[] = 'issue_status_updated';
        }

        // A group assignee is the same assignee change in Redmine (one assigned_to_id column); here it
        // is recorded under its own key.
        if ($details->contains(fn (JournalDetail $d) => $d->property === 'attr' && in_array($d->prop_key, ['assigned_to_id', 'assigned_to_group_id'], true))) {
            $keys[] = 'issue_assigned_to_updated';
        }

        if ($details->contains(fn (JournalDetail $d) => $d->property === 'attr' && $d->prop_key === 'priority_id')) {
            $keys[] = 'issue_priority_updated';
        }

        if ($details->contains(fn (JournalDetail $d) => $d->property === 'attr' && $d->prop_key === 'fixed_version_id')) {
            $keys[] = 'issue_fixed_version_updated';
        }

        if ($details->contains(fn (JournalDetail $d) => $d->property === 'attachment' && filled($d->new_value))) {
            $keys[] = 'issue_attachment_added';
        }

        return $keys;
    }
}
