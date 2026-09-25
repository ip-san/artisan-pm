<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use App\Support\Activity\ActivityEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Redmine's `GET /projects/{id}/repository/revisions.atom` (and the named-
 * repository form `/repository/{repo_id}/revisions.atom`):
 * RepositoriesController#revisions responding to format.atom, the
 * repository's changesets newest first, capped at Setting.feeds_limit.
 * Reuses the same title/url shape ChangesetActivityProvider already builds
 * for the project activity feed.
 */
final class RepositoryRevisionsAtomController extends Controller
{
    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Repository::class, $project]);

        $repository = $project->resolveRepository($request->route('repositoryParam'));
        abort_if($repository === null, 404);

        $entries = $repository->changesets()
            ->latest('committed_on')
            ->limit(ActivityFeedController::limit())
            ->get()
            ->map(fn (Changeset $changeset) => new ActivityEntry(
                type: 'changeset',
                title: "{$changeset->shortRevision()}: ".Str::of((string) $changeset->comments)->trim()->limit(80),
                url: route($repository->routeName('repository.show'), $repository->routeParameters(['changeset' => $changeset])),
                authorName: $changeset->committer,
                occurredAt: $changeset->committed_on,
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name}: ".__('リビジョン'),
            'alternateUrl' => route($repository->routeName('repository.index'), $repository->routeParameters()),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
