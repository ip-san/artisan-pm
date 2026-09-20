<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The entries of several providers across several projects, newest first.
 * A provider that reads many projects at once is asked once; any other
 * (a plugin's, say) is asked project by project.
 */
final class CrossProjectEntries
{
    /**
     * @param  Collection<int, ActivityProvider>  $providers
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, ActivityEntry>
     */
    public static function collect(Collection $providers, Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        return $providers
            ->flatMap(fn (ActivityProvider $provider) => $provider instanceof MultiProjectActivityProvider
                ? $provider->entriesForProjects($projects, $viewer, $from, $to)
                : $projects->flatMap(fn (Project $project) => $provider->entries($project, $viewer, $from, $to)))
            ->sortByDesc('occurredAt')
            ->values();
    }
}
