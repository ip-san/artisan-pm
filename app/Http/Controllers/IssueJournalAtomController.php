<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Support\Authorization\AuthorizationService;
use App\Support\Feeds\JournalFeedEntries;
use App\Support\Query\CustomFieldVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Redmine's `GET /issues/{id}.atom`: one issue's own journal history
 * (`IssuesController#show` renders `journals/index` — the same template
 * the cross-issue changes feed uses, `common/feed.atom.builder`) rather
 * than the cross-issue changes list `IssueChangesAtomController` covers.
 * Reuses the exact same journal visibility/description rules
 * (JournalFeedEntries) and feed view.
 */
final class IssueJournalAtomController extends Controller
{
    public function __invoke(Request $request, Project $project, Issue $issue): Response
    {
        Gate::authorize('view', $issue);

        $user = $request->user();
        $customFields = CustomField::query()->with('roles')->get()->keyBy('id');
        $visibility = CustomFieldVisibility::for($user);
        $entries = new JournalFeedEntries(app(AuthorizationService::class));

        // Newest first, matching every other feed this app builds
        // (feeds.issue-changes's <updated> reads $journals->first()) —
        // Redmine's own HTML issue page shows the oldest journal first by
        // default, but its Atom feed for one issue reuses the very same
        // 'journals/index' template as the cross-issue changes feed, which
        // this app's equivalent (IssueChangesAtomController) already
        // orders newest first.
        $journals = $issue->journals()
            ->with(['issue.project', 'issue.tracker', 'user', 'details'])
            ->latest('created_at')
            ->latest('id')
            ->get();

        $journals = $entries->visible($journals, $user, $customFields);

        $xml = view('feeds.issue-changes', [
            'journals' => $journals,
            'title' => "{$issue->project->name} - {$issue->tracker->name} #{$issue->id}: {$issue->subject}",
            'alternateUrl' => route('issues.show', [$issue->project, $issue]),
            'details' => fn (Journal $journal) => $entries->describe($journal, $customFields, $visibility),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
