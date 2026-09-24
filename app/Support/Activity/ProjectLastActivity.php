<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Redmine's Project.load_last_activity_date: the date of each project's
 * newest activity event the viewer may see, over every registered event
 * type. A provider that can't aggregate (LastActivityProvider) is skipped,
 * since reading its whole history would be too costly for a list.
 */
final class ProjectLastActivity
{
    public function __construct(
        private readonly ActivityProviderRegistry $registry,
    ) {}

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Carbon> keyed by project id
     */
    public function forProjects(Collection $projects, ?User $viewer): Collection
    {
        if ($projects->isEmpty()) {
            return collect();
        }

        $latest = collect();

        foreach ($this->registry->all() as $provider) {
            if (! $provider instanceof LastActivityProvider) {
                continue;
            }

            foreach ($provider->lastActivityByProject($projects, $viewer) as $projectId => $time) {
                if (! $latest->has($projectId) || $time->greaterThan($latest->get($projectId))) {
                    $latest->put($projectId, $time);
                }
            }
        }

        return $latest;
    }

    /**
     * MAX($timeColumn) grouped by $projectColumn over $query.
     *
     * @return Collection<int, Carbon>
     */
    public static function maxByProject(Builder $query, string $projectColumn, string $timeColumn): Collection
    {
        return $query
            ->reorder()
            ->select("{$projectColumn} as last_activity_project_id")
            ->selectRaw("MAX({$timeColumn}) as last_activity_at")
            ->groupBy($projectColumn)
            ->toBase()
            ->get()
            ->filter(fn (object $row) => $row->last_activity_at !== null)
            ->mapWithKeys(fn (object $row) => [(int) $row->last_activity_project_id => Carbon::parse($row->last_activity_at)]);
    }

    /**
     * Merges per-project maxima, keeping the later time.
     *
     * @param  Collection<int, Carbon>  ...$maxima
     * @return Collection<int, Carbon>
     */
    public static function merge(Collection ...$maxima): Collection
    {
        $merged = collect();

        foreach ($maxima as $maximum) {
            foreach ($maximum as $projectId => $time) {
                if (! $merged->has($projectId) || $time->greaterThan($merged->get($projectId))) {
                    $merged->put($projectId, $time);
                }
            }
        }

        return $merged;
    }
}
