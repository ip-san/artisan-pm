<?php

declare(strict_types=1);

namespace App\Support\Activity;

use Carbon\CarbonInterface;

final readonly class ActivityEntry
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public ?string $authorName,
        public CarbonInterface $occurredAt,
        // Nullable and optional (defaulted, not a required positional
        // param) so every existing named-argument call site keeps
        // compiling unchanged. Left null by providers with no reliable
        // User FK for the author (Document has none at all; Changeset's
        // committer is a raw SCM string that isn't necessarily mapped to
        // a User) — the profile page's "recent activity" section can only
        // filter by this, so those two providers' entries simply never
        // appear there, matching how Redmine's own Fetcher only surfaces
        // events whose journalized/author association resolves to a User.
        public ?int $authorId = null,
    ) {}
}
