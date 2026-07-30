<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Issue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class IssueCreated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $mentionedLogins  see MentionParser —
     *                                               every `@login` found in the issue's description, since there's
     *                                               no "before" text to diff against on creation.
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly array $mentionedLogins = [],
    ) {}
}
