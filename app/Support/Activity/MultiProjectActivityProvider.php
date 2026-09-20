<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A provider that can read several projects in one query, for the
 * cross-project feed: instead of one scan per project it does one scan per
 * provider. It applies its own view_* check to each project and leaves out
 * the ones the viewer may not see, exactly as ActivityProvider::entries() does.
 */
interface MultiProjectActivityProvider extends ActivityProvider
{
    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, ActivityEntry>
     */
    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection;
}
