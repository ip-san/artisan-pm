<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Redmine's `display_subprojects_issues`: whether a project's issue list and
 * totals also cover its subprojects. The projects in scope are the project
 * itself plus, with the setting on, every descendant the viewer may look at.
 * Off by default here (Redmine's default is on), so an existing installation
 * keeps the per-project lists it has always shown.
 */
final class SubprojectScope
{
    public static function enabled(): bool
    {
        return (bool) Setting::get('display_subprojects_issues', false);
    }

    /**
     * @return Collection<int, Project>
     */
    public static function projectsForIssues(Project $project, ?User $user): Collection
    {
        return self::projects($project, fn (Project $candidate) => $user?->can('viewAny', [Issue::class, $candidate]) ?? false);
    }

    /**
     * @return Collection<int, Project>
     */
    public static function projectsForTimeEntries(Project $project, ?User $user): Collection
    {
        return self::projects($project, fn (Project $candidate) => $user?->can('viewAny', [TimeEntry::class, $candidate]) ?? false);
    }

    /**
     * @param  callable(Project): bool  $mayLook
     * @return Collection<int, Project>
     */
    private static function projects(Project $project, callable $mayLook): Collection
    {
        if (! self::enabled() || $project->_rgt - $project->_lft <= 1) {
            return collect([$project]);
        }

        return Project::query()
            ->where('_lft', '>=', $project->_lft)
            ->where('_rgt', '<=', $project->_rgt)
            ->orderBy('_lft')
            ->get()
            ->filter(fn (Project $candidate) => $candidate->is($project) || $mayLook($candidate))
            ->values();
    }
}
