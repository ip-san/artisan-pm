<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Activity\ActivityEntry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Matches Redmine's IssuesController#index responding to format.atom:
 * this project's open issues, most recently updated first. Reuses the
 * same visibility scope (Issue::scopeVisibleTo()) the HTML issue list
 * applies, and the same "open" default the list's own statusFilter
 * starts on — unlike the HTML list, this doesn't reflect the current
 * filter/sort/group state (Redmine's own atom feed does, but that would
 * mean threading QueryFilterEngine's filter state through a query
 * string here too; a fixed "recently updated open issues" feed is a
 * smaller, still useful first cut, consistent with how the News/Board
 * Atom feeds are also unfiltered "most recent N" views).
 */
final class IssueAtomController extends Controller
{
    public function __invoke(Project $project): Response
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        $entries = Issue::query()
            ->where('project_id', $project->id)
            ->visibleTo(auth()->user(), $project)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->with('author')
            ->latest('updated_at')
            ->limit(ActivityFeedController::LIMIT)
            ->get()
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
