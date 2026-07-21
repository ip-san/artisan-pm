<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One module's contribution to a project's aggregated activity feed.
 * Registered centrally (see ActivityServiceProvider) rather than each
 * feature module reaching into a shared feed itself, so the feed page
 * doesn't need to know about every module's models.
 */
interface ActivityProvider
{
    /**
     * Discriminator used for the feed's per-type filter checkboxes, and as
     * a CSS/icon hook — e.g. 'issue', 'wiki-edit', 'changeset'.
     */
    public function type(): string;

    public function label(): string;

    /**
     * Implementations are responsible for their own authorization (the
     * relevant view_* permission) and for returning an empty collection
     * when the viewer can't see this module at all — the feed page
     * doesn't re-check permissions per entry.
     *
     * @return Collection<int, ActivityEntry>
     */
    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection;
}
