<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Builds the filterable/sortable field set for a project's time entries —
 * mirrors IssueFilterFieldRegistry's shape (native columns only; time
 * entries don't carry custom fields) so TimeEntry list/report views reuse
 * the exact same QueryFilterEngine the issue list does.
 */
final class TimeEntryFilterFieldRegistry
{
    /**
     * @return Collection<string, FilterableField>
     */
    public static function forProject(Project $project): Collection
    {
        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $integerOperators = self::integerOperators();

        /** @var array<int, FilterableField> $fields */
        $fields = [
            new NativeColumnFilter('user_id', '担当者', 'user_id', FilterFieldType::Select, $selectOperators, fn () => $project->users->pluck('name', 'id')->all()),
            new NativeColumnFilter('activity_id', '作業分類', 'activity_id', FilterFieldType::Select, $selectOperators, fn () => $project->activities(includeInactive: true)->pluck('name', 'id')->all()),
            new NativeColumnFilter('spent_on', '日付', 'spent_on', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('hours', '時間', 'hours', FilterFieldType::Integer, $integerOperators),
        ];

        return collect($fields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * Cross-project variant of forProject() for the global time entries
     * list — user_id/activity_id options become the union across every
     * given project (each project can have its own activity
     * enable/disable overrides) instead of one project's own.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<string, FilterableField>
     */
    public static function forProjects(Collection $projects): Collection
    {
        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $integerOperators = self::integerOperators();

        $users = $projects->flatMap(fn (Project $project) => $project->users)->unique('id');
        $activities = $projects->flatMap(fn (Project $project) => $project->activities(includeInactive: true))->unique('id');

        /** @var array<int, FilterableField> $fields */
        $fields = [
            new NativeColumnFilter('project_id', 'プロジェクト', 'project_id', FilterFieldType::Select, $selectOperators, fn () => $projects->pluck('name', 'id')->all()),
            new NativeColumnFilter('user_id', '担当者', 'user_id', FilterFieldType::Select, $selectOperators, fn () => $users->pluck('name', 'id')->all()),
            new NativeColumnFilter('activity_id', '作業分類', 'activity_id', FilterFieldType::Select, $selectOperators, fn () => $activities->pluck('name', 'id')->all()),
            new NativeColumnFilter('spent_on', '日付', 'spent_on', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('hours', '時間', 'hours', FilterFieldType::Integer, $integerOperators),
        ];

        return collect($fields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function selectOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function dateOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::InTheLastDays];
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function integerOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between];
    }
}
