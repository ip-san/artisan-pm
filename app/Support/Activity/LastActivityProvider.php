<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A provider that can tell when each project last had one of its events,
 * in one aggregate query — Redmine's find_events with :last_by_project,
 * behind the project list's last activity date. It applies the same
 * view_* check (and item visibility) as its feed entries.
 */
interface LastActivityProvider extends ActivityProvider
{
    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Carbon> the newest event time, keyed by project id (projects without one are left out)
     */
    public function lastActivityByProject(Collection $projects, ?User $viewer): Collection;
}
