<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Activity\ActivityEntry;
use App\Support\Format\Hours;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\ListQueryString;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Matches Redmine's TimelogController#index responding to format.atom:
 * this project's time entries — or, without a project (`/time_entries.atom`),
 * those of every project the reader may view_time_entries in — newest
 * created first (Redmine orders by created_on, not spent_on). Reuses the
 * list's own filters (ListQueryString), the same way IssueAtomController
 * does for issues.atom.
 *
 * Redmine's acts_as_event title is "N hours (issue or project title)" and
 * links to the timelog index filtered to that issue/project; this app
 * links straight to the issue (when visible) or the project's own time
 * entries list, which is where that filtered timelog index would land.
 */
final class TimeEntryAtomController extends Controller
{
    public function __invoke(Request $request, ?Project $project = null): Response
    {
        if ($project !== null) {
            Gate::authorize('viewAny', [TimeEntry::class, $project]);
        }

        $state = ListQueryString::fromRequestInput($request->query());

        if ($project !== null) {
            $scopeProjects = SubprojectScope::projectsForTimeEntries($project, auth()->user(), $state['filters']);
            $engine = new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProject($project, scopeProjects: $scopeProjects));
        } else {
            $scopeProjects = Project::query()
                ->with('users')
                ->get()
                ->filter(fn (Project $candidate) => auth()->user()?->can('viewAny', [TimeEntry::class, $candidate]))
                ->values();
            $engine = new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProjects($scopeProjects));
        }

        $query = TimeEntry::query()
            ->visibleToAcrossProjects(auth()->user(), $scopeProjects)
            ->with(['project', 'user', 'issue.project']);

        $query = $engine->applyFilters($query, $state['filters']);

        $entries = $query
            ->latest('created_at')
            ->limit(ActivityFeedController::limit())
            ->get()
            ->map(function (TimeEntry $entry) {
                $issue = $entry->issue;
                $relatedTitle = $issue !== null && auth()->user()?->can('view', $issue) ? "#{$issue->id} {$issue->subject}" : $entry->project->name;

                return new ActivityEntry(
                    type: 'time-entry',
                    title: __(':hours時間 (:activity)', ['hours' => Hours::format((float) $entry->hours, false), 'activity' => $relatedTitle]),
                    url: $issue !== null && auth()->user()?->can('view', $issue)
                        ? route('issues.show', [$issue->project, $issue])
                        : route('time-entries.index', $entry->project),
                    authorName: $entry->user->displayName(),
                    occurredAt: $entry->created_at ?? throw new LogicException('TimeEntry is missing created_at.'),
                );
            });

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => ($project !== null ? "{$project->name}: " : '').__('作業時間').' - '.config('app.name'),
            'alternateUrl' => $project !== null ? route('time-entries.index', $project) : route('time-entries.global-index'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
