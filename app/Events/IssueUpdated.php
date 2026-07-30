<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class IssueUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * $journal is null for a comment-only save with no attribute/custom
     * field changes only when there's also no comment — IssueService only
     * dispatches this event at all when there's a journal-worthy change or
     * a comment, so in practice $journal is non-null whenever this fires.
     * It stays nullable for callers that construct this event directly
     * (e.g. tests) without a journal.
     *
     * @param  array<int, string>  $mentionedLogins  see MentionParser —
     *                                               logins newly added to the description in this update, unioned
     *                                               with every login mentioned in the new comment (a fresh Journal
     *                                               note has no "before" text to diff against).
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly User $actor,
        public readonly ?Journal $journal = null,
        public readonly array $mentionedLogins = [],
    ) {}
}
