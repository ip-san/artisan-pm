<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\QueryType;
use App\Models\Setting;

/**
 * Redmine draws both the Gantt chart and the calendar from an IssueQuery:
 * the issue list's saved queries are offered there too (loading one
 * applies its filters) and the current filters can be saved as an issue
 * query, gated by save_queries. Only filters apply — unlike the issue
 * list's own saveQuery()/loadQuery(), columns/sort/group_by are not read
 * or written, since neither view has any of those. The consuming
 * component (with InteractsWithQueryFilters) supplies
 * queryScopeProject() (null for a cross-project view) and applyFilters().
 */
trait UsesSavedIssueQueriesForFiltering
{
    use ManagesSavedQueries;

    protected function savedQueryType(): QueryType
    {
        return QueryType::Issue;
    }

    /**
     * Nothing besides the filters: an edit leaves column_names/
     * sort_criteria/group_by exactly as they already are on the query,
     * since neither the Gantt nor the Calendar ever reads or offers to
     * change them — always overwriting them with the create-time defaults
     * would silently blow away whatever the issue list had set on a query
     * simply because it was renamed from here. (The project scope is kept
     * on an edit too, see savedQueryTargetProject().)
     *
     * @return array<string, mixed>
     */
    protected function savedQueryAttributes(): array
    {
        return [];
    }

    /**
     * A new query gets the site's default list columns, as Redmine's Gantt
     * "Save" does.
     *
     * @return array<string, mixed>
     */
    protected function savedQueryCreateAttributes(): array
    {
        return [
            'column_names' => Setting::get('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']),
            'sort_criteria' => [],
            'group_by' => null,
        ];
    }

    /**
     * Applies a saved issue query's filters to the chart (its columns and
     * sort do not apply to a Gantt chart).
     */
    public function loadQuery(int $queryId): void
    {
        $query = $this->savedQueries->firstWhere('id', $queryId);

        abort_if($query === null, 404);

        $this->applySavedQueryFilters($query);
        $this->applyFilters();
    }
}
