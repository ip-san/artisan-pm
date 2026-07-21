<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\EnumerationType;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\Enumeration;
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
        $selectOperators = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
        $dateOperators = [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::InTheLastDays];
        $integerOperators = [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between];

        /** @var array<int, FilterableField> $fields */
        $fields = [
            new NativeColumnFilter('user_id', '担当者', 'user_id', FilterFieldType::Select, $selectOperators, fn () => $project->users->pluck('name', 'id')->all()),
            new NativeColumnFilter('activity_id', '作業分類', 'activity_id', FilterFieldType::Select, $selectOperators, fn () => Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('spent_on', '日付', 'spent_on', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('hours', '時間', 'hours', FilterFieldType::Integer, $integerOperators),
        ];

        return collect($fields)->keyBy(fn (FilterableField $field) => $field->key());
    }
}
