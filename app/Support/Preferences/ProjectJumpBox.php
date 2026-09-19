<?php

declare(strict_types=1);

namespace App\Support\Preferences;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Redmine's project jump box (Redmine::ProjectJumpBox): the header dropdown
 * that lists the projects to switch to — the ones the user used most
 * recently, their bookmarks, then everything they are a member of. The
 * recent ones are remembered in the user's preferences, as many as
 * `recently_used_projects` says, and a bookmarked project never doubles as a
 * recent one.
 */
final class ProjectJumpBox
{
    /**
     * Remembers that `$user` just worked in `$project`. Writes only when the
     * order actually changes, so browsing inside one project costs nothing.
     */
    public static function projectUsed(User $user, Project $project): void
    {
        $limit = max(0, (int) $user->preference('recently_used_projects'));
        $current = self::storedIds($user);

        if ($limit === 0 || $user->bookmarkedProjects()->whereKey($project->id)->exists()) {
            return;
        }

        $updated = array_slice([$project->id, ...array_values(array_diff($current, [$project->id]))], 0, $limit);

        if ($updated !== $current) {
            UserPreferences::save($user, ['recently_used_project_ids' => $updated]);
        }
    }

    /**
     * @return array{recent: Collection<int, Project>, bookmarked: Collection<int, Project>, all: Collection<int, Project>}
     */
    public static function entries(User $user): array
    {
        $usable = fn (Project $project) => $project->status === ProjectStatus::Active && $user->can('view', $project);

        $all = $user->projects()->where('projects.status', ProjectStatus::Active)->orderBy('projects.name')->limit(200)->get()->filter($usable)->values();
        $bookmarked = $user->bookmarkedProjects()->where('projects.status', ProjectStatus::Active)->orderBy('projects.name')->get()->filter($usable)->values();

        $ids = self::storedIds($user);
        $recent = Project::query()->whereIn('id', $ids)->get()->filter($usable)
            ->reject(fn (Project $project) => $bookmarked->contains('id', $project->id))
            ->sortBy(fn (Project $project) => array_search($project->id, $ids, true))
            ->take(max(0, (int) $user->preference('recently_used_projects')))
            ->values();

        return ['recent' => $recent, 'bookmarked' => $bookmarked, 'all' => $all];
    }

    /**
     * @return array<int, int>
     */
    private static function storedIds(User $user): array
    {
        $ids = $user->preference('recently_used_project_ids');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
}
