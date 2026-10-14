<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use App\Models\Project;

/**
 * The project heading rows of a chart that takes in several projects
 * (Redmine's Gantt#render walking Project.project_tree): each project
 * with its depth among the listed ones.
 */
final class GanttProjectTree
{
    /**
     * $projects, in nested-set order (_lft), each paired with how many of
     * the listed projects enclose it.
     *
     * @param  iterable<Project>  $projects
     * @return array<int, array{0: Project, 1: int}>
     */
    public static function withDepths(iterable $projects): array
    {
        $tree = [];
        $openRights = [];

        foreach ($projects as $project) {
            while ($openRights !== [] && end($openRights) < $project->_lft) {
                array_pop($openRights);
            }

            $tree[] = [$project, count($openRights)];
            $openRights[] = $project->_rgt;
        }

        return $tree;
    }
}
