<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Issues\AssigneeChoice;
use App\Support\Issues\SubprojectScope;
use Closure;
use Illuminate\Support\Collection;

/**
 * Builds the full set of filterable/sortable fields for a project's issue
 * list — native columns plus that project's applicable custom fields —
 * keyed by field key so QueryFilterEngine can resolve stored filter/sort/
 * group definitions against it.
 *
 * Some filters depend on who is looking (Redmine offers is_private only to
 * someone who may set it, for instance); $viewer defaults to the signed-in
 * user.
 */
final class IssueFilterFieldRegistry
{
    /**
     * @return Collection<string, FilterableField>
     */
    public static function forProject(Project $project, ?User $viewer = null): Collection
    {
        $viewer ??= auth()->user();

        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $textOperators = self::textOperators();
        $integerOperators = self::integerOperators();

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('status_id', __('ステータス'), 'status_id', FilterFieldType::Select, $selectOperators, fn () => IssueStatus::query()->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('tracker_id', __('トラッカー'), 'tracker_id', FilterFieldType::Select, $selectOperators, fn () => $project->trackers->pluck('name', 'id')->all()),
            new NativeColumnFilter('priority_id', __('優先度'), 'priority_id', FilterFieldType::Select, $selectOperators, fn () => Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('category_id', __('カテゴリ'), 'category_id', FilterFieldType::Select, $selectOperators, fn () => $project->issueCategories->pluck('name', 'id')->all()),
            self::assigneeFilter($selectOperators, fn () => $project->users, collect([$project->id]), $viewer),
            new NativeColumnFilter('author_id', __('作成者'), 'author_id', FilterFieldType::Select, $selectOperators, fn () => $project->users->pluck('name', 'id')->all()),
            new NativeColumnFilter('fixed_version_id', __('対象バージョン'), 'fixed_version_id', FilterFieldType::Select, $selectOperators, fn () => $project->versions->pluck('name', 'id')->all()),
            new NativeColumnFilter('subject', __('題名'), 'subject', FilterFieldType::Text, $textOperators),
            new NativeColumnFilter('start_date', __('開始日'), 'start_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('due_date', __('期日'), 'due_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('created_at', __('作成日'), 'created_at', FilterFieldType::Date, $dateOperators, storesTime: true),
            new NativeColumnFilter('updated_at', __('更新日'), 'updated_at', FilterFieldType::Date, $dateOperators, storesTime: true),
            new NativeColumnFilter('closed_on', __('終了日'), 'closed_on', FilterFieldType::Date, $dateOperators, storesTime: true),
            new NativeColumnFilter('done_ratio', __('進捗率'), 'done_ratio', FilterFieldType::Integer, $integerOperators),
        ];

        $scopeProjects = null;
        $resolveScopeProjects = function () use (&$scopeProjects, $project, $viewer): Collection {
            return $scopeProjects ??= SubprojectScope::projectsForIssues($project, $viewer);
        };

        $customFields = self::customFieldFilters(
            CustomField::query()
                ->where('customized_type', CustomizableType::Issue)
                ->where('is_filter', true)
                ->whereHas('trackers', fn ($query) => $query->whereIn('trackers.id', $project->trackers->pluck('id')))
                ->with(['trackers', 'projects', 'roles'])
                ->orderBy('position')
                ->get()
                ->filter(fn (CustomField $field) => $field->appliesToProject($project)),
            fn () => collect([$project])->concat($resolveScopeProjects())->unique('id')->values(),
            $viewer,
        );

        $extraFields = (new IssueExtraFilterFields($resolveScopeProjects, $viewer, app(AuthorizationService::class), $project))->fields();

        return collect($nativeFields)->concat($extraFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
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
    public static function forProjects(Collection $projects, ?User $viewer = null): Collection
    {
        $viewer ??= auth()->user();

        $selectOperators = self::selectOperators();
        $dateOperators = self::dateOperators();
        $textOperators = self::textOperators();
        $integerOperators = self::integerOperators();

        $projects->each->loadMissing(['trackers', 'issueCategories', 'users', 'versions']);

        $trackers = $projects->flatMap(fn (Project $project) => $project->trackers)->unique('id');
        $categories = $projects->flatMap(fn (Project $project) => $project->issueCategories)->unique('id');
        $users = $projects->flatMap(fn (Project $project) => $project->users)->unique('id');
        $versions = $projects->flatMap(fn (Project $project) => $project->versions)->unique('id');

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('project_id', __('プロジェクト'), 'project_id', FilterFieldType::Select, $selectOperators, fn () => $projects->pluck('name', 'id')->all()),
            new NativeColumnFilter('status_id', __('ステータス'), 'status_id', FilterFieldType::Select, $selectOperators, fn () => IssueStatus::query()->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('tracker_id', __('トラッカー'), 'tracker_id', FilterFieldType::Select, $selectOperators, fn () => $trackers->pluck('name', 'id')->all()),
            new NativeColumnFilter('priority_id', __('優先度'), 'priority_id', FilterFieldType::Select, $selectOperators, fn () => Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->pluck('name', 'id')->all()),
            new NativeColumnFilter('category_id', __('カテゴリ'), 'category_id', FilterFieldType::Select, $selectOperators, fn () => $categories->pluck('name', 'id')->all()),
            self::assigneeFilter($selectOperators, fn () => $users, $projects->pluck('id'), $viewer),
            new NativeColumnFilter('author_id', __('作成者'), 'author_id', FilterFieldType::Select, $selectOperators, fn () => $users->pluck('name', 'id')->all()),
            new NativeColumnFilter('fixed_version_id', __('対象バージョン'), 'fixed_version_id', FilterFieldType::Select, $selectOperators, fn () => $versions->pluck('name', 'id')->all()),
            new NativeColumnFilter('subject', __('題名'), 'subject', FilterFieldType::Text, $textOperators),
            new NativeColumnFilter('start_date', __('開始日'), 'start_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('due_date', __('期日'), 'due_date', FilterFieldType::Date, $dateOperators),
            new NativeColumnFilter('created_at', __('作成日'), 'created_at', FilterFieldType::Date, $dateOperators, storesTime: true),
            new NativeColumnFilter('done_ratio', __('進捗率'), 'done_ratio', FilterFieldType::Integer, $integerOperators),
        ];

        $customFields = self::customFieldFilters(
            CustomField::query()
                ->where('customized_type', CustomizableType::Issue)
                ->where('is_filter', true)
                ->whereHas('trackers', fn ($query) => $query->whereIn('trackers.id', $trackers->pluck('id')))
                ->with(['trackers', 'projects', 'roles'])
                ->orderBy('position')
                ->get()
                ->filter(fn (CustomField $field) => $projects->contains(fn (Project $project) => $field->appliesToProject($project))),
            fn () => $projects,
            $viewer,
        );

        $extraFields = (new IssueExtraFilterFields(fn () => $projects, $viewer, app(AuthorizationService::class)))->fields();

        return collect($nativeFields)->concat($extraFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * The custom fields the viewer may see in at least one of the listed
     * projects (Redmine's IssueQuery#issue_custom_fields.visible), each
     * limited to the rows of the projects where it is visible. A hidden
     * field is simply absent, so a stored or requested filter on it is
     * ignored rather than applied.
     *
     * @param  Collection<int, CustomField>  $fields  with `roles` loaded
     * @param  callable(): Collection<int, Project>  $projects  every project whose issues the list may show
     * @return Collection<int, CustomFieldFilter>
     */
    private static function customFieldFilters(Collection $fields, callable $projects, ?User $viewer): Collection
    {
        $visibility = CustomFieldVisibility::for($viewer);
        $restricted = $fields->contains(fn (CustomField $field) => ! $viewer?->is_admin && $field->roles->isNotEmpty());
        $listed = $restricted ? $projects() : collect();

        return $fields
            ->map(fn (CustomField $field) => ['field' => $field, 'projectIds' => $visibility->visibleProjectIds($field, $listed)])
            ->reject(fn (array $entry) => $entry['projectIds'] === [])
            ->map(fn (array $entry): FilterableField => new CustomFieldFilter($entry['field'], $entry['projectIds']))
            ->values();
    }

    /**
     * Redmine's assigned_to_values: `<< me >>`, the users, and — with
     * issue_group_assignment on — the member groups (`group:<id>`).
     *
     * @param  array<int, FilterOperator>  $operators
     * @param  Closure(): Collection<int, User>  $users
     * @param  Collection<int, int>  $projectIds
     */
    private static function assigneeFilter(array $operators, Closure $users, Collection $projectIds, ?User $viewer): AssigneeFilter
    {
        return new AssigneeFilter(__('担当者'), $operators, function () use ($users, $projectIds, $viewer): array {
            $options = ($viewer !== null ? ['me' => __('<< 自分 >>')] : []) + $users()->pluck('name', 'id')->all();

            if (Issue::groupAssignmentEnabled()) {
                Group::query()
                    ->whereHas('memberships', fn ($members) => $members->whereIn('project_id', $projectIds))
                    ->orderBy('name')
                    ->get()
                    ->each(function (Group $group) use (&$options): void {
                        $options[AssigneeChoice::forGroup($group)] = $group->name;
                    });
            }

            return $options;
        }, $viewer);
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
