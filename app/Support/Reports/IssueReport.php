<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Issues\SubprojectScope;
use Illuminate\Support\Collection;

/**
 * The numbers behind Redmine's issue report and its per-dimension details
 * page (ReportsController#issue_report / #issue_report_details): issues
 * counted by status along one dimension. Only issues the viewer may see are
 * counted (Redmine's Issue.visible), and with display_subprojects_issues on
 * the subprojects' issues count too.
 */
final class IssueReport
{
    /** dimension => [title, issues column] */
    public const DIMENSIONS = [
        'tracker' => ['トラッカー', 'tracker_id'],
        'priority' => ['優先度', 'priority_id'],
        'category' => ['カテゴリ', 'category_id'],
        'version' => ['対象バージョン', 'fixed_version_id'],
        'assigned_to' => ['担当者', 'assigned_to_id'],
        'author' => ['作成者', 'author_id'],
        'subproject' => ['サブプロジェクト', 'project_id'],
    ];

    public function __construct(
        private readonly Project $project,
        private readonly ?User $viewer,
    ) {}

    public static function isDimension(string $dimension): bool
    {
        return array_key_exists($dimension, self::DIMENSIONS);
    }

    public static function title(string $dimension): string
    {
        return self::DIMENSIONS[$dimension][0];
    }

    public static function column(string $dimension): string
    {
        return self::DIMENSIONS[$dimension][1];
    }

    /**
     * Whether a dimension has a trailing "none" row (issues without a
     * category, version or assignee).
     */
    public static function hasNoneRow(string $dimension): bool
    {
        return in_array($dimension, ['category', 'version', 'assigned_to'], true);
    }

    /**
     * The project plus, with the setting on, its subprojects the viewer may
     * look at.
     *
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return SubprojectScope::projectsForIssues($this->project, $this->viewer);
    }

    /**
     * The subprojects reported on: every project in scope except this one.
     *
     * @return Collection<int, Project>
     */
    public function subprojects(): Collection
    {
        return $this->projects()->reject(fn (Project $project) => $project->is($this->project))->values();
    }

    /**
     * The dimensions worth showing: the subproject one only when there is
     * something in it.
     *
     * @return array<int, string>
     */
    public function dimensions(): array
    {
        $dimensions = array_keys(self::DIMENSIONS);

        return $this->subprojects()->isEmpty() ? array_values(array_diff($dimensions, ['subproject'])) : $dimensions;
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    public function rows(string $dimension): Collection
    {
        return match ($dimension) {
            'tracker' => $this->project->trackers()->orderBy('position')->get(),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get(),
            'category' => $this->project->issueCategories()->orderBy('name')->get(),
            'version' => $this->project->versions()->orderBy('name')->get(),
            'assigned_to' => $this->project->assignableUsers(),
            'author' => $this->project->users()->orderBy('name')->get(),
            'subproject' => $this->subprojects(),
        };
    }

    /**
     * dimension value (or 'none') => status_id => count, over the issues the
     * viewer can see.
     *
     * @return array<int|string, array<int, int>>
     */
    public function counts(string $dimension): array
    {
        $column = self::column($dimension);

        $rows = Issue::query()
            ->visibleToAcrossProjects($this->viewer, $this->projects())
            ->selectRaw("{$column} as dimension_value, status_id, COUNT(*) as total")
            ->groupBy($column, 'status_id')
            ->get();

        $pivoted = [];

        foreach ($rows as $row) {
            $pivoted[$row->dimension_value ?? 'none'][$row->status_id] = (int) $row->total;
        }

        return $pivoted;
    }

    /**
     * The rows of a dimension ready to print: each with its key and label,
     * plus the "none" row when it has any issues.
     *
     * @param  array<int|string, array<int, int>>  $counts
     * @return array<int, array{key: int|string, label: string}>
     */
    public function gridRows(string $dimension, array $counts): array
    {
        $rows = $this->rows($dimension)->map(fn ($row) => ['key' => $row->id, 'label' => $row->name])->all();

        if (self::hasNoneRow($dimension) && array_key_exists('none', $counts)) {
            $rows[] = ['key' => 'none', 'label' => 'なし'];
        }

        return $rows;
    }
}
