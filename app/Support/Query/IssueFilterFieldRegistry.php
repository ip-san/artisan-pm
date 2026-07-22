<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Builds the full set of filterable/sortable fields for a project's issue
 * list — native columns plus that project's applicable custom fields —
 * keyed by field key so QueryFilterEngine can resolve stored filter/sort/
 * group definitions against it.
 */
final class IssueFilterFieldRegistry
{
    /**
     * @return Collection<string, FilterableField>
     */
    public static function forProject(Project $project): Collection
    {
        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $textOperators = self::textOperators();
        $integerOperators = self::integerOperators();

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('status_id', 'ステータス', 'status_id', FilterFieldType::Select, $selectOperators, fn () => IssueStatus::query()->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('tracker_id', 'トラッカー', 'tracker_id', FilterFieldType::Select, $selectOperators, fn () => $project->trackers->pluck('name', 'id')->all()),
            new NativeColumnFilter('priority_id', '優先度', 'priority_id', FilterFieldType::Select, $selectOperators, fn () => Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('category_id', 'カテゴリ', 'category_id', FilterFieldType::Select, $selectOperators, fn () => $project->issueCategories->pluck('name', 'id')->all()),
            new NativeColumnFilter('assigned_to_id', '担当者', 'assigned_to_id', FilterFieldType::Select, $selectOperators, fn () => $project->users->pluck('name', 'id')->all()),
            new NativeColumnFilter('author_id', '作成者', 'author_id', FilterFieldType::Select, $selectOperators, fn () => $project->users->pluck('name', 'id')->all()),
            new NativeColumnFilter('fixed_version_id', '対象バージョン', 'fixed_version_id', FilterFieldType::Select, $selectOperators, fn () => $project->versions->pluck('name', 'id')->all()),
            new NativeColumnFilter('subject', '題名', 'subject', FilterFieldType::Text, $textOperators),
            new NativeColumnFilter('start_date', '開始日', 'start_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('due_date', '期日', 'due_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('created_at', '作成日', 'created_at', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('done_ratio', '進捗率', 'done_ratio', FilterFieldType::Integer, $integerOperators),
        ];

        $customFields = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->whereIn('trackers.id', $project->trackers->pluck('id')))
            ->with(['trackers', 'projects'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $field->appliesToProject($project))
            ->map(fn (CustomField $field): FilterableField => new CustomFieldFilter($field));

        return collect($nativeFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * Cross-project variant of forProject() for the global issue list —
     * native fields whose options are project-relationship-scoped
     * (tracker/category/assignee/author/version) become the union across
     * every given project instead of one project's own; status/priority
     * are already global lookups. Custom fields are the union of every
     * field that applies to at least one of the given projects. Callers
     * should eager-load trackers/issueCategories/users/versions on
     * $projects first to avoid an N+1 per project here.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<string, FilterableField>
     */
    public static function forProjects(Collection $projects): Collection
    {
        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $textOperators = self::textOperators();
        $integerOperators = self::integerOperators();

        $trackers = $projects->flatMap(fn (Project $project) => $project->trackers)->unique('id');
        $categories = $projects->flatMap(fn (Project $project) => $project->issueCategories)->unique('id');
        $users = $projects->flatMap(fn (Project $project) => $project->users)->unique('id');
        $versions = $projects->flatMap(fn (Project $project) => $project->versions)->unique('id');

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('project_id', 'プロジェクト', 'project_id', FilterFieldType::Select, $selectOperators, fn () => $projects->pluck('name', 'id')->all()),
            new NativeColumnFilter('status_id', 'ステータス', 'status_id', FilterFieldType::Select, $selectOperators, fn () => IssueStatus::query()->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('tracker_id', 'トラッカー', 'tracker_id', FilterFieldType::Select, $selectOperators, fn () => $trackers->pluck('name', 'id')->all()),
            new NativeColumnFilter('priority_id', '優先度', 'priority_id', FilterFieldType::Select, $selectOperators, fn () => Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('category_id', 'カテゴリ', 'category_id', FilterFieldType::Select, $selectOperators, fn () => $categories->pluck('name', 'id')->all()),
            new NativeColumnFilter('assigned_to_id', '担当者', 'assigned_to_id', FilterFieldType::Select, $selectOperators, fn () => $users->pluck('name', 'id')->all()),
            new NativeColumnFilter('author_id', '作成者', 'author_id', FilterFieldType::Select, $selectOperators, fn () => $users->pluck('name', 'id')->all()),
            new NativeColumnFilter('fixed_version_id', '対象バージョン', 'fixed_version_id', FilterFieldType::Select, $selectOperators, fn () => $versions->pluck('name', 'id')->all()),
            new NativeColumnFilter('subject', '題名', 'subject', FilterFieldType::Text, $textOperators),
            new NativeColumnFilter('start_date', '開始日', 'start_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('due_date', '期日', 'due_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('created_at', '作成日', 'created_at', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('done_ratio', '進捗率', 'done_ratio', FilterFieldType::Integer, $integerOperators),
        ];

        $customFields = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->whereIn('trackers.id', $trackers->pluck('id')))
            ->with(['trackers', 'projects'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $projects->contains(fn (Project $project) => $field->appliesToProject($project)))
            ->map(fn (CustomField $field): FilterableField => new CustomFieldFilter($field));

        return collect($nativeFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function selectOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function dateOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::InTheLastDays, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function textOperators(): array
    {
        return [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::Equals];
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function integerOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between];
    }
}
