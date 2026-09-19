<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query;
use App\Models\Setting;
use App\Models\User;

/**
 * Redmine's IssueQuery.default: the saved query an issue list opens on when
 * the visit names no filter of its own. The user's own choice comes first,
 * then the project's, then the site-wide one; the project and site defaults
 * must be public queries so everyone can use them.
 */
final class DefaultIssueQuery
{
    public static function for(?User $user, Project $project): ?Query
    {
        $ownId = $user?->preference('default_issue_query');

        if ($ownId !== null) {
            $own = self::find((int) $ownId, $project);

            if ($own !== null && $own->visibleTo($user)) {
                return $own;
            }
        }

        foreach ([$project->default_issue_query_id, Setting::get('default_issue_query')] as $candidateId) {
            $candidate = filled($candidateId) ? self::find((int) $candidateId, $project) : null;

            if ($candidate !== null && $candidate->visibility === QueryVisibility::Public) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * An issue query that applies to this project: a global one or the
     * project's own.
     */
    private static function find(int $id, Project $project): ?Query
    {
        return Query::query()
            ->where('type', QueryType::Issue->value)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->find($id);
    }
}
