<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Redmine's issue autocomplete (`/issues/auto_complete`): candidates for the
 * parent or a related issue, found by `#123` / `123` (the id) or by a part of
 * the subject. Only issues the viewer may see are offered, in the project or —
 * for relations, when cross_project_issue_relations is on — in every project
 * they can look at.
 */
final class IssueSuggestions
{
    public const LIMIT = 10;

    /**
     * @return Collection<int, Issue>
     */
    public static function search(?User $viewer, string $term, Project $project, bool $allowOtherProjects = false, ?int $excludeIssueId = null): Collection
    {
        $term = trim($term);

        if ($term === '' || $viewer === null) {
            return new Collection;
        }

        $projects = $allowOtherProjects && Setting::get('cross_project_issue_relations', false)
            ? Project::query()->get()->filter(fn (Project $candidate) => $viewer->can('viewAny', [Issue::class, $candidate]))->values()
            : collect([$project])->filter(fn (Project $candidate) => $viewer->can('viewAny', [Issue::class, $candidate]));

        $idTerm = ltrim($term, '#');
        $like = '%'.addcslashes($term, '%_\\').'%';
        $operator = Issue::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return Issue::query()
            ->visibleToAcrossProjects($viewer, $projects)
            ->when($excludeIssueId !== null, fn ($query) => $query->whereKeyNot($excludeIssueId))
            ->where(function ($query) use ($idTerm, $like, $operator): void {
                if (ctype_digit($idTerm)) {
                    $query->whereKey((int) $idTerm)->orWhere('subject', $operator, $like);
                } else {
                    $query->where('subject', $operator, $like);
                }
            })
            ->with('project')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
