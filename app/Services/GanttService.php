<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Support\Gantt\GanttRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fetches a project's full issue tree, depth-first ordered, in a single
 * query via a recursive CTE — Issue's hierarchy is an adjacency list
 * (parent_id), and Eloquent has no query-builder support for recursive
 * queries, so this is unavoidably raw SQL. Table names are still pulled
 * from the models rather than hardcoded, so a rename doesn't silently
 * break this; the only genuinely dynamic input (project_id) is bound as
 * a parameter, never interpolated.
 *
 * Postgres-specific (array concatenation via ||, ORDER BY on an array
 * column for depth-first ordering) — matches the project's committed
 * choice of Postgres over portability.
 */
final class GanttService
{
    /**
     * @param  Collection<int, int>|null  $onlyIssueIds  restrict the tree to these issues (plus their ancestors, kept so depth and grouping stay coherent); null returns the full tree
     * @return Collection<int, GanttRow>
     */
    public function issueTree(Project $project, ?Collection $onlyIssueIds = null): Collection
    {
        $issues = (new Issue)->getTable();
        $trackers = (new Tracker)->getTable();
        $statuses = (new IssueStatus)->getTable();

        $rows = DB::select(<<<SQL
            WITH RECURSIVE issue_tree AS (
                SELECT
                    i.id, i.parent_id, i.subject, i.start_date, i.due_date, i.done_ratio,
                    tr.name AS tracker_name, st.name AS status_name, st.is_closed,
                    0 AS depth, ARRAY[i.id] AS tree_path
                FROM {$issues} i
                INNER JOIN {$trackers} tr ON tr.id = i.tracker_id
                INNER JOIN {$statuses} st ON st.id = i.status_id
                WHERE i.project_id = ? AND i.parent_id IS NULL

                UNION ALL

                SELECT
                    i.id, i.parent_id, i.subject, i.start_date, i.due_date, i.done_ratio,
                    tr.name AS tracker_name, st.name AS status_name, st.is_closed,
                    t.depth + 1, t.tree_path || i.id
                FROM {$issues} i
                INNER JOIN {$trackers} tr ON tr.id = i.tracker_id
                INNER JOIN {$statuses} st ON st.id = i.status_id
                INNER JOIN issue_tree t ON i.parent_id = t.id
                WHERE i.project_id = ?
            )
            SELECT * FROM issue_tree ORDER BY tree_path
            SQL, [$project->id, $project->id]);

        $tree = collect($rows)->map(GanttRow::fromRow(...));

        if ($onlyIssueIds === null) {
            return $tree;
        }

        // Keep each matched issue plus its ancestor chain — a filtered
        // child rendered without its parents would show a misleading
        // depth indent pointing at nothing.
        $byId = $tree->keyBy('id');
        $keep = [];

        foreach ($onlyIssueIds as $id) {
            $current = $byId->get($id);

            while ($current !== null && ! isset($keep[$current->id])) {
                $keep[$current->id] = true;
                $current = $current->parentId !== null ? $byId->get($current->parentId) : null;
            }
        }

        return $tree->filter(fn (GanttRow $row) => isset($keep[$row->id]))->values();
    }
}
