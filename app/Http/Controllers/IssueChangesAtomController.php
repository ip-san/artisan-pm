<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QueryType;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Feeds\JournalFeedEntries;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\CustomFieldVisibility;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\ListQueryString;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Redmine's JournalsController#index: an Atom feed of the latest issue
 * changes — the notes and field changes of each journal, newest first — for
 * one project or, without one, every project the reader may see. Only
 * journals of issues the reader may see; private notes only for those who
 * may read them or wrote them. Redmine caps this feed at 25 entries.
 *
 * Like Redmine's (retrieve_query), the feed covers the issues of an issue
 * query: the issue list's own URL state (statusFilter and the
 * activeFilterKeys/filterOperators/filterValues filters, which the list's
 * link carries) or a saved query the reader may see (`query_id`). Without
 * either it covers open issues, IssueQuery's default filter.
 */
final class IssueChangesAtomController extends Controller
{
    private const int LIMIT = 25;

    public function __invoke(Request $request, ?Project $project = null): Response
    {
        $user = $request->user();
        $authorization = app(AuthorizationService::class);

        if ($project !== null) {
            Gate::authorize('viewAny', [Issue::class, $project]);
        }

        $savedQuery = $this->savedQuery($request, $user);
        $filters = $savedQuery?->filters ?? ListQueryString::fromRequestInput($request->query())['filters'];

        $projects = ($project !== null ? SubprojectScope::projectsForIssues($project, $user, $filters) : Project::query()->get())
            ->filter(fn (Project $candidate) => $authorization->can($user, 'view_issues', $candidate))
            ->values();
        $engine = new QueryFilterEngine($project !== null
            ? IssueFilterFieldRegistry::forProject($project, $user, $projects)
            : IssueFilterFieldRegistry::forProjects($projects, $user));

        $issues = Issue::query()->visibleToAcrossProjects($user, $projects);
        $statusFilter = $this->statusFilter($request, $savedQuery !== null || $filters !== []);

        if ($statusFilter !== 'all') {
            $issues->whereHas('status', fn (Builder $status) => $status->where('is_closed', $statusFilter === 'closed'));
        }

        $issueIds = $engine->applyFilters($issues, $filters)->reorder()->select('issues.id');

        $customFields = CustomField::query()->with('roles')->get()->keyBy('id');
        $visibility = CustomFieldVisibility::for($user);
        $entries = new JournalFeedEntries($authorization);

        $journals = Journal::query()
            ->whereIn('issue_id', $issueIds)
            ->with(['issue.project', 'issue.tracker', 'user', 'details'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LIMIT * 4)
            ->get();

        $journals = $entries->visible($journals, $user, $customFields)->take(self::LIMIT)->values();

        $xml = view('feeds.issue-changes', [
            'journals' => $journals,
            'title' => ($project !== null ? $project->name : (string) Setting::get('app_title', config('app.name'))).': '.__('変更の詳細'),
            'alternateUrl' => $project !== null ? route('issues.index', $project) : route('issues.global-index'),
            'details' => fn (Journal $journal) => $entries->describe($journal, $customFields, $visibility),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    /**
     * The saved issue query named by query_id: it must exist (404) and be
     * visible to the reader (403), as in Redmine's retrieve_query.
     */
    private function savedQuery(Request $request, ?User $user): ?SavedQuery
    {
        $id = $request->query('query_id');

        if ($id === null) {
            return null;
        }

        abort_unless(is_string($id) && ctype_digit($id), 404);

        $query = SavedQuery::query()->where('type', QueryType::Issue->value)->find((int) $id);

        abort_if($query === null, 404);
        abort_unless($query->visibleTo($user), 403);

        return $query;
    }

    /**
     * `open`, `closed` or `all`: the list's statusFilter when given; else
     * every status when filters or a saved query decide, and open issues
     * for a bare feed (IssueQuery's default "status: open").
     */
    private function statusFilter(Request $request, bool $hasFilters): string
    {
        $requested = $request->query('statusFilter');

        if (in_array($requested, ['open', 'closed', 'all'], true)) {
            return $requested;
        }

        return $hasFilters ? 'all' : 'open';
    }
}
