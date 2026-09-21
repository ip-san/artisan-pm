<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IssueRelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Support\Gantt\GanttRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fetches a project's full issue tree, depth-first ordered. Issue's
 * hierarchy is an adjacency list (parent_id): one flat query loads the
 * project's issues, and the ordering and depth are worked out in PHP, so
 * this runs unchanged on PostgreSQL, MySQL/MariaDB and SQLite (a recursive
 * CTE that orders by an accumulated path needs array types only PostgreSQL
 * has). Table names are still pulled from the models rather than
 * hardcoded, so a rename doesn't silently break this.
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

        $rows = DB::table("{$issues} as i")
            ->join("{$trackers} as tr", 'tr.id', '=', 'i.tracker_id')
            ->join("{$statuses} as st", 'st.id', '=', 'i.status_id')
            ->where('i.project_id', $project->id)
            ->orderBy('i.id')
            ->get([
                'i.id', 'i.parent_id', 'i.subject', 'i.start_date', 'i.due_date', 'i.done_ratio',
                'tr.name as tracker_name', 'st.name as status_name', 'st.is_closed',
            ]);

        $tree = $this->orderDepthFirst($rows);

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

    /**
     * Roots first, each followed by its descendants, siblings by id. An
     * issue whose parent lives in another project is unreachable from any
     * root of this project and is left out, as it always has been.
     *
     * @param  Collection<int, object>  $rows  this project's issues, ordered by id
     * @return Collection<int, GanttRow>
     */
    private function orderDepthFirst(Collection $rows): Collection
    {
        $roots = [];
        $childrenByParentId = [];

        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                $roots[] = $row;
            } else {
                $childrenByParentId[(int) $row->parent_id][] = $row;
            }
        }

        $ordered = collect();
        $pending = [];

        foreach (array_reverse($roots) as $root) {
            $pending[] = [$root, 0];
        }

        while ($pending !== []) {
            [$row, $depth] = array_pop($pending);
            $row->depth = $depth;
            $ordered->push(GanttRow::fromRow($row));

            foreach (array_reverse($childrenByParentId[(int) $row->id] ?? []) as $child) {
                $pending[] = [$child, $depth + 1];
            }
        }

        return $ordered;
    }

    /**
     * precedes/blocks relations where both ends are currently visible on
     * the chart — matches Redmine's Gantt#relations (lib/redmine/helpers/
     * gantt.rb), which only draws these two relation types (Redmine's own
     * DRAW_TYPES), each ends only drawn once both issues are rendered.
     *
     * @param  Collection<int, int>  $visibleIssueIds
     * @return Collection<int, IssueRelation>
     */
    public function relationsWithin(Collection $visibleIssueIds): Collection
    {
        if ($visibleIssueIds->isEmpty()) {
            return collect();
        }

        return IssueRelation::query()
            ->whereIn('relation_type', [IssueRelationType::Precedes->value, IssueRelationType::Blocks->value])
            ->whereIn('issue_from_id', $visibleIssueIds)
            ->whereIn('issue_to_id', $visibleIssueIds)
            ->get();
    }
}
