<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Activity\ActivityEntry;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\ListQueryString;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Matches Redmine's IssuesController#index responding to format.atom:
 * this project's issues, most recently updated first. Reuses the same
 * visibility scope (Issue::scopeVisibleTo()) the HTML issue list applies,
 * and reads the list's own URL state — statusFilter (open by default, like
 * the list), the activeFilterKeys/filterOperators/filterValues filters and
 * up to three sort levels — through ListQueryString, so the Atom link on
 * the list reproduces the current view. As in Redmine, the filters and
 * sort decide which issues make the feed cap (feeds_limit) and the
 * entries are then shown newest-updated first.
 */
final class IssueAtomController extends Controller
{
    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        $state = ListQueryString::fromRequestInput($request->query());
        $requestedStatus = $request->query('statusFilter', 'open');
        $statusFilter = is_string($requestedStatus) ? $requestedStatus : 'open';
        $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project));

        $query = Issue::query()
            ->where('project_id', $project->id)
            ->visibleTo(auth()->user(), $project)
            ->with('author');

        if (in_array($statusFilter, ['open', 'closed'], true)) {
            $isClosed = $statusFilter === 'closed';
            $query->whereHas('status', fn ($status) => $status->where('is_closed', $isClosed));
        }

        $query = $engine->applyFilters($query, $state['filters']);
        $query = $state['sort'] !== [] ? $engine->applySort($query, $state['sort']) : $query->latest('updated_at');

        $entries = $query
            ->limit(ActivityFeedController::limit())
            ->get()
            ->sortByDesc('updated_at')
            ->values()
            ->map(fn (Issue $issue) => new ActivityEntry(
                type: 'issue',
                title: "#{$issue->id} {$issue->subject}",
                url: route('issues.show', [$project, $issue]),
                authorName: $issue->author->name,
                occurredAt: $issue->updated_at ?? throw new LogicException('Issue is missing updated_at.'),
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name}: Issues - ".config('app.name'),
            'alternateUrl' => route('issues.index', $project),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
