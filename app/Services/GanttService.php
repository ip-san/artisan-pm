<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IssueRelationType;
use App\Enums\VersionSharing;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\Version;
use App\Support\Gantt\GanttRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * @param  Project|Collection<int, Project>  $projects  the chart's project, or it and the subprojects it takes in (display_subprojects_issues)
     * @param  Collection<int, int>|null  $onlyIssueIds  restrict the tree to these issues (plus their ancestors, kept so depth and grouping stay coherent); null returns the full tree
     * @param  Collection<int, int>|null  $visibleIssueIds  the issues the viewer may see (Issue::scopeVisibleTo()); the others are left out entirely, and an issue whose parent is left out is drawn as a root — null draws every issue
     * @return Collection<int, GanttRow>
     */
    public function issueTree(Project|Collection $projects, ?Collection $onlyIssueIds = null, ?Collection $visibleIssueIds = null): Collection
    {
        $projectIds = $projects instanceof Project ? [$projects->id] : $projects->pluck('id')->all();
        $rows = $this->baseQuery()->whereIn('i.project_id', $projectIds)->get();

        // With subprojects in scope, each project's issues follow its
        // parent's (in the order given, the project tree's), as Redmine
        // draws a project's issues before its subprojects'.
        if (count($projectIds) > 1) {
            $position = array_flip($projectIds);
            $rows = $rows->sortBy([
                fn (object $a, object $b) => $position[(int) $a->project_id] <=> $position[(int) $b->project_id],
                fn (object $a, object $b) => (int) $a->id <=> (int) $b->id,
            ])->values();
        }

        if ($visibleIssueIds !== null) {
            $visible = array_flip($visibleIssueIds->map(fn ($id) => (int) $id)->all());
            $rows = $rows->filter(fn (object $row) => isset($visible[(int) $row->id]))->values();
            $rows->each(function (object $row) use ($visible): void {
                if ($row->parent_id !== null && ! isset($visible[(int) $row->parent_id])) {
                    $row->parent_id = null;
                }
            });
        }

        return $this->keepMatched($this->orderDepthFirst($rows), $onlyIssueIds);
    }

    /**
     * The cross-project chart's trees (Redmine's /issues/gantt): the issues
     * $visibleIssues selects, one depth-first tree per project. An issue
     * whose parent isn't drawn — hidden from the viewer, or in another
     * project (Redmine groups issues under their own project too) — is
     * drawn as a root of its own project's tree.
     *
     * @param  Builder<Issue>  $visibleIssues  the issues the viewer may see (Issue::scopeVisibleToAcrossProjects())
     * @param  Collection<int, int>|null  $onlyIssueIds  see issueTree()
     * @return Collection<int, Collection<int, GanttRow>> keyed by project id
     */
    public function issueTreesByProject(Builder $visibleIssues, ?Collection $onlyIssueIds = null): Collection
    {
        $rows = $this->baseQuery()
            ->whereIn('i.id', $visibleIssues->clone()->select($visibleIssues->qualifyColumn('id'))->toBase())
            ->get();

        $projectById = $rows->mapWithKeys(fn (object $row) => [(int) $row->id => (int) $row->project_id])->all();
        $rows->each(function (object $row) use ($projectById): void {
            if ($row->parent_id !== null && ($projectById[(int) $row->parent_id] ?? null) !== (int) $row->project_id) {
                $row->parent_id = null;
            }
        });

        return $rows->groupBy(fn (object $row) => (int) $row->project_id)
            ->map(fn (Collection $projectRows) => $this->keepMatched($this->orderDepthFirst($projectRows->values()), $onlyIssueIds));
    }

    /**
     * Every issue with its tracker and status, by id. Table names are
     * pulled from the models rather than hardcoded.
     */
    private function baseQuery(): QueryBuilder
    {
        $issues = (new Issue)->getTable();
        $trackers = (new Tracker)->getTable();
        $statuses = (new IssueStatus)->getTable();

        return DB::table("{$issues} as i")
            ->join("{$trackers} as tr", 'tr.id', '=', 'i.tracker_id')
            ->join("{$statuses} as st", 'st.id', '=', 'i.status_id')
            ->orderBy('i.id')
            ->select([
                'i.id', 'i.parent_id', 'i.project_id', 'i.fixed_version_id', 'i.subject', 'i.start_date', 'i.due_date', 'i.done_ratio',
                'tr.name as tracker_name', 'st.name as status_name', 'st.is_closed',
            ]);
    }

    /**
     * Keep each matched issue plus its ancestor chain — a filtered child
     * rendered without its parents would show a misleading depth indent
     * pointing at nothing.
     *
     * @param  Collection<int, GanttRow>  $tree
     * @param  Collection<int, int>|null  $onlyIssueIds
     * @return Collection<int, GanttRow>
     */
    private function keepMatched(Collection $tree, ?Collection $onlyIssueIds): Collection
    {
        if ($onlyIssueIds === null) {
            return $tree;
        }

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
     * The milestones drawn on $project's chart: its own versions with a due
     * date, plus dated versions shared from another project (sharing other
     * than none) that one of the drawn issues targets — Redmine's
     * Gantt#project_versions, which lists the versions of the project's
     * issues wherever they're defined.
     *
     * @param  Project|Collection<int, Project>  $projects  the chart's project, or it and its subprojects in scope
     * @param  Collection<int, GanttRow>  $rows  the issue rows drawn for $project
     * @return Collection<int, Version>
     */
    public function milestones(Project|Collection $projects, Collection $rows): Collection
    {
        $targeted = $rows->pluck('fixedVersionId')->filter()->unique()->values();
        $projectIds = $projects instanceof Project ? [$projects->id] : $projects->pluck('id')->all();

        return Version::query()
            ->whereNotNull('due_date')
            ->where(fn (Builder $query) => $query
                ->whereIn('project_id', $projectIds)
                ->orWhere(fn (Builder $shared) => $shared
                    ->whereIn('id', $targeted)
                    ->where('sharing', '!=', VersionSharing::None->value)))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * milestones() for every project at once: one query for all the projects' versions and the
     * shared versions their rows target, then each project gets the same set milestones() would
     * have returned for it alone. The global chart has one project per heading, and a query per
     * heading was most of what it cost.
     *
     * @param  Collection<int, Collection<int, GanttRow>>  $treesByProject  keyed by project id
     * @return Collection<int, \Illuminate\Database\Eloquent\Collection<int, Version>> keyed by project id, in due-date order
     */
    public function milestonesByProject(Collection $treesByProject): Collection
    {
        $treesByProject = $treesByProject->filter(fn (Collection $tree) => $tree->isNotEmpty());

        if ($treesByProject->isEmpty()) {
            return collect();
        }

        $targetedByProject = $treesByProject->map(fn (Collection $tree) => $tree->pluck('fixedVersionId')->filter()->unique()->values());
        $versions = Version::query()
            ->whereNotNull('due_date')
            ->where(fn (Builder $query) => $query
                ->whereIn('project_id', $treesByProject->keys()->all())
                ->orWhere(fn (Builder $shared) => $shared
                    ->whereIn('id', $targetedByProject->flatten()->unique()->all())
                    ->where('sharing', '!=', VersionSharing::None->value)))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return $treesByProject->map(fn (Collection $tree, int $projectId) => $versions
            ->filter(fn (Version $version) => $version->project_id === $projectId
                || ($targetedByProject[$projectId]->contains($version->id) && $version->sharing !== VersionSharing::None))
            ->values());
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
