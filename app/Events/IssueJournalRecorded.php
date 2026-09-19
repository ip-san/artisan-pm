<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A journal recorded on its own — an attachment added or removed, a relation
 * added or removed — as opposed to the one IssueService::update() writes for
 * an edit. Redmine mails every journal that has details or notes; this event
 * carries those to the mail listener only. It is deliberately not the
 * IssueUpdated event, which also drives the `issue.updated` webhook: Redmine
 * fires webhooks from the issue record's own changes, not from journals.
 */
final class IssueJournalRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Issue $issue,
        public readonly User $actor,
        public readonly Journal $journal,
    ) {}
}
