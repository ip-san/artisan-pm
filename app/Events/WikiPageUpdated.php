<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WikiPage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WikiPageUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * $textChanged distinguishes an actual content edit from a rename/move
     * (WikiPageService::update()/moveToProject() both dispatch this event
     * unconditionally, since webhook subscribers reasonably want to know
     * about either) — matches Redmine's WikiContent#after_update, which
     * only mails on `saved_change_to_text?`. Webhook dispatch is
     * unaffected by this flag; only the mail-notification listener reads
     * it, since Redmine's own webhook-equivalent (none exists in core,
     * this app's own addition) has no such restriction to mirror.
     *
     * @param  array<int, string>  $mentionedLogins  see MentionParser —
     *                                               logins newly added to the text in this update (empty for a
     *                                               rename/move, which never diffs text).
     */
    public function __construct(
        public readonly WikiPage $wikiPage,
        public readonly bool $textChanged = true,
        public readonly array $mentionedLogins = [],
    ) {}
}
