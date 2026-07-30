<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WikiPage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WikiPageCreated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $mentionedLogins  see MentionParser —
     *                                               every `@login` found in the page's initial text.
     */
    public function __construct(
        public readonly WikiPage $wikiPage,
        public readonly array $mentionedLogins = [],
    ) {}
}
