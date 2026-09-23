<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\IssueRelationType;
use App\Enums\ProjectStatus;
use App\Enums\VersionStatus;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\SearchService;
use App\Support\Authorization\AuthorizationService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The issue filters beyond the plain columns of IssueFilterFieldRegistry,
 * after Redmine 7.0's IssueQuery#initialize_available_filters and its
 * `sql_for_<field>_field` methods. Shared by the per-project and the
 * cross-project registry.
 *
 * The filters only ever narrow a query the caller has already limited to
 * the issues the viewer may see. Where a filter looks into rows that have
 * visibility rules of their own (private notes), the rule is applied per
 * issue's project: a project's list can show its subprojects' issues, so
 * the permission is checked on every project the list covers, not only on
 * the one whose list it is.
 */
final class IssueExtraFilterFields
{
    /** @var array<string, Collection<int, int>> */
    private array $projectIdsByPermission = [];

    /** @var ?Collection<int, Project> */
    private ?Collection $resolvedScopeProjects = null;

    /** @var ?array<int, string> */
    private ?array $visibleProjectOptions = null;

    /** @var ?array<int, string> */
    private ?array $visibleGroupOptions = null;

    /**
     * @param  Closure(): Collection<int, Project>  $scopeProjects  the projects whose issues the list covers, resolved only when a filter needs them
     * @param  ?Project  $listProject  the project whose list it is, null for the cross-project list
     */
    public function __construct(
        private readonly Closure $scopeProjects,
        private readonly ?User $viewer,
        private readonly AuthorizationService $authorization,
        private readonly ?Project $listProject = null,
    ) {}

    /**
     * @return array<int, FilterableField>
     */
    public function fields(): array
    {
        return array_values(array_filter([
            $this->description(),
            $this->notes(),
            $this->estimatedHours(),
            $this->isPrivate(),
            $this->issueId(),
            $this->parentId(),
            $this->childId(),
            ...$this->relationFilters(),
            $this->watcherId(),
            $this->updatedBy(),
            $this->lastUpdatedBy(),
            $this->memberOfGroup(),
            $this->assignedToRole(),
            $this->authorGroup(),
            $this->authorRole(),
            $this->attachment(),
            $this->attachmentDescription(),
            $this->fixedVersionDueDate(),
            $this->fixedVersionStatus(),
            $this->projectStatus(),
            $this->spentTime(),
            $this->anySearchable(),
        ]));
    }

    private function description(): FilterableField
    {
        return new CallbackFilter(
            'description',
            __('説明'),
            FilterFieldType::Text,
            self::textOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                self::applyText($query, $query->qualifyColumn('description'), $operator, $values);

                return $query;
            },
        );
    }

    /**
     * Redmine's sql_for_notes_field: an issue matches when one of its
     * journals the viewer may read has matching notes; the negated
     * operators mean that none does.
     */
    private function notes(): FilterableField
    {
        return new CallbackFilter(
            'notes',
            __('コメント'),
            FilterFieldType::Text,
            self::textOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $positive = match ($operator) {
                    FilterOperator::NotContains => FilterOperator::Contains,
                    FilterOperator::IsEmpty => FilterOperator::IsNotEmpty,
                    default => $operator,
                };

                $matchingJournal = fn (QueryBuilder $journals) => $this->readableJournals($journals, $query)
                    ->where(fn (QueryBuilder $notes) => self::applyText($notes, 'journals.notes', $positive, $values));

                return $positive === $operator
                    ? $query->whereExists($matchingJournal)
                    : $query->whereNotExists($matchingJournal);
            },
        );
    }

    private function estimatedHours(): FilterableField
    {
        return new NativeColumnFilter('estimated_hours', __('予定工数'), 'estimated_hours', FilterFieldType::Integer, [
            FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty,
        ]);
    }

    /**
     * Offered, as in Redmine, only to someone who may make issues private
     * somewhere (set_issues_private or set_own_issues_private).
     */
    private function isPrivate(): ?FilterableField
    {
        if (! $this->authorization->canGlobally($this->viewer, 'set_issues_private')
            && ! $this->authorization->canGlobally($this->viewer, 'set_own_issues_private')) {
            return null;
        }

        return new CallbackFilter(
            'is_private',
            __('非公開'),
            FilterFieldType::Select,
            [FilterOperator::Equals, FilterOperator::NotEquals],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $flags = array_values(array_unique(array_map(fn ($value) => (string) $value !== '0', $values)));

                return $operator === FilterOperator::NotEquals
                    ? $query->whereNotIn($query->qualifyColumn('is_private'), $flags)
                    : $query->whereIn($query->qualifyColumn('is_private'), $flags);
            },
            fn () => ['1' => __('はい'), '0' => __('いいえ')],
        );
    }

    /**
     * Redmine's sql_for_issue_id_field: "is" takes a comma separated list
     * of ids; the other operators compare the id itself.
     */
    private function issueId(): FilterableField
    {
        return new CallbackFilter(
            'issue_id',
            __('課題番号'),
            FilterFieldType::Integer,
            [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator !== FilterOperator::Equals) {
                    return FilterOperatorApplier::apply($query, $query->getModel()->getQualifiedKeyName(), $operator, $values);
                }

                if ($values === []) {
                    return $query;
                }

                $ids = self::idList($values);

                return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
            },
        );
    }

    /**
     * Redmine's sql_for_parent_id_field ("tree" filter): "is" matches the
     * direct children of the given issues, "contains" every descendant of
     * them; none/any test whether the issue has a parent at all.
     */
    private function parentId(): FilterableField
    {
        return new CallbackFilter(
            'parent_id',
            __('親課題'),
            FilterFieldType::Integer,
            self::treeOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                $column = $query->qualifyColumn('parent_id');

                if ($operator === FilterOperator::IsEmpty) {
                    return $query->whereNull($column);
                }

                if ($operator === FilterOperator::IsNotEmpty) {
                    return $query->whereNotNull($column);
                }

                if ($values === []) {
                    return $query;
                }

                $ids = self::idList($values);

                if ($ids === []) {
                    return $query->whereRaw('1 = 0');
                }

                return $operator === FilterOperator::Contains
                    ? self::whereDescendantOf($query, $ids)
                    : $query->whereIn($column, $ids);
            },
        );
    }

    /**
     * Redmine's sql_for_child_id_field ("tree" filter): "is" matches the
     * parents of the given issues, "contains" every ancestor of them;
     * none/any test whether the issue has subtasks.
     */
    private function childId(): FilterableField
    {
        return new CallbackFilter(
            'child_id',
            __('サブタスク'),
            FilterFieldType::Integer,
            self::treeOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                $table = $query->getModel()->getTable();
                $key = $query->getModel()->getQualifiedKeyName();
                $children = fn (QueryBuilder $children) => $children->select($children->raw('1'))
                    ->from("{$table} as subtasks")
                    ->whereColumn('subtasks.parent_id', $key);

                if ($operator === FilterOperator::IsEmpty) {
                    return $query->whereNotExists($children);
                }

                if ($operator === FilterOperator::IsNotEmpty) {
                    return $query->whereExists($children);
                }

                if ($values === []) {
                    return $query;
                }

                $ids = self::idList($values);

                if ($ids === []) {
                    return $query->whereRaw('1 = 0');
                }

                if ($operator === FilterOperator::Contains) {
                    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

                    return $query->whereRaw(
                        "{$key} IN (WITH RECURSIVE tree_ancestors AS ("
                        ."SELECT parent_id AS id FROM {$table} WHERE id IN ({$placeholders}) AND parent_id IS NOT NULL"
                        ." UNION SELECT parent.parent_id FROM {$table} parent INNER JOIN tree_ancestors ON parent.id = tree_ancestors.id WHERE parent.parent_id IS NOT NULL"
                        .') SELECT id FROM tree_ancestors)',
                        $ids,
                    );
                }

                return $query->whereIn($key, fn (QueryBuilder $parents) => $parents->select('parent_id')
                    ->from($table)
                    ->whereIn('id', $ids)
                    ->whereNotNull('parent_id'));
            },
        );
    }

    /**
     * One filter per relation name, as Redmine's IssueRelation::TYPES —
     * both directions of each stored type ("blocks" and "blocked"), and
     * "copied_from" for the reverse of copied_to.
     *
     * @return array<int, FilterableField>
     */
    private function relationFilters(): array
    {
        $blocks = IssueRelationType::Blocks->value;
        $duplicates = IssueRelationType::Duplicates->value;
        $precedes = IssueRelationType::Precedes->value;
        $follows = IssueRelationType::Follows->value;
        $copiedTo = IssueRelationType::CopiedTo->value;
        $relates = IssueRelationType::Relates->value;

        // [stored relation_type, the issue's own side, the related side].
        // This app stores "follows" rows as they were entered (Redmine
        // turns them into "precedes"), so precedes/follows read both.
        $relations = [
            'relates' => [__('関連'), [[$relates, 'issue_from_id', 'issue_to_id'], [$relates, 'issue_to_id', 'issue_from_id']]],
            'duplicates' => [__('重複する'), [[$duplicates, 'issue_from_id', 'issue_to_id']]],
            'duplicated' => [__('重複されている'), [[$duplicates, 'issue_to_id', 'issue_from_id']]],
            'blocks' => [__('ブロックする'), [[$blocks, 'issue_from_id', 'issue_to_id']]],
            'blocked' => [__('ブロックされている'), [[$blocks, 'issue_to_id', 'issue_from_id']]],
            'precedes' => [__('先行'), [[$precedes, 'issue_from_id', 'issue_to_id'], [$follows, 'issue_to_id', 'issue_from_id']]],
            'follows' => [__('後続'), [[$follows, 'issue_from_id', 'issue_to_id'], [$precedes, 'issue_to_id', 'issue_from_id']]],
            'copied_to' => [__('コピー先'), [[$copiedTo, 'issue_from_id', 'issue_to_id']]],
            'copied_from' => [__('コピー元'), [[$copiedTo, 'issue_to_id', 'issue_from_id']]],
        ];

        return collect($relations)
            ->map(fn (array $relation, string $key) => new CallbackFilter(
                $key,
                $relation[0],
                FilterFieldType::Integer,
                [
                    FilterOperator::Equals, FilterOperator::NotEquals,
                    FilterOperator::AnyIssuesInProject, FilterOperator::AnyIssuesNotInProject, FilterOperator::NoIssuesInProject,
                    FilterOperator::AnyOpenIssues, FilterOperator::NoOpenIssues,
                    FilterOperator::IsEmpty, FilterOperator::IsNotEmpty,
                ],
                fn (Builder $query, FilterOperator $operator, array $values) => $this->applyRelation($query, $relation[1], $operator, $values),
                fn () => $this->visibleProjectOptions(),
            ))
            ->values()
            ->all();
    }

    /**
     * Redmine's sql_for_relations. "is not", "none", "no issues in
     * project" and "no open issues" are the negation of their positive
     * counterpart.
     *
     * @param  Builder<*>  $query
     * @param  array<int, array{0: string, 1: string, 2: string}>  $forms
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    private function applyRelation(Builder $query, array $forms, FilterOperator $operator, array $values): Builder
    {
        if ($operator->requiresValue() && $values === []) {
            return $query;
        }

        if (in_array($operator, [FilterOperator::Equals, FilterOperator::NotEquals], true) && self::idList($values) === []) {
            return $query->whereRaw('1 = 0');
        }

        $issueKey = $query->getModel()->getQualifiedKeyName();
        $table = $query->getModel()->getTable();

        $constraint = match ($operator) {
            FilterOperator::Equals, FilterOperator::NotEquals => fn (QueryBuilder $relations, string $related) => $relations->whereIn($related, self::idList($values)),
            FilterOperator::AnyIssuesInProject, FilterOperator::NoIssuesInProject, FilterOperator::AnyIssuesNotInProject => fn (QueryBuilder $relations, string $related) => $relations
                ->join("{$table} as related_issues", 'related_issues.id', '=', $related)
                ->where(fn (QueryBuilder $inProject) => $operator === FilterOperator::AnyIssuesNotInProject
                    ? $inProject->whereNotIn('related_issues.project_id', $this->chosenProjectIds($values))
                    : $inProject->whereIn('related_issues.project_id', $this->chosenProjectIds($values))),
            FilterOperator::AnyOpenIssues, FilterOperator::NoOpenIssues => fn (QueryBuilder $relations, string $related) => $relations
                ->join("{$table} as related_issues", 'related_issues.id', '=', $related)
                ->join('issue_statuses as related_statuses', 'related_statuses.id', '=', 'related_issues.status_id')
                ->where('related_statuses.is_closed', false),
            default => fn (QueryBuilder $relations, string $related) => $relations,
        };

        $related = function (Builder $any) use ($forms, $issueKey, $constraint): void {
            foreach ($forms as [$type, $ownSide, $relatedSide]) {
                $any->orWhereExists(fn (QueryBuilder $relations) => $constraint(
                    $relations->select($relations->raw('1'))
                        ->from('issue_relations')
                        ->where('issue_relations.relation_type', $type)
                        ->whereColumn("issue_relations.{$ownSide}", $issueKey),
                    "issue_relations.{$relatedSide}",
                ));
            }
        };

        $negated = in_array($operator, [FilterOperator::NotEquals, FilterOperator::NoIssuesInProject, FilterOperator::NoOpenIssues, FilterOperator::IsEmpty], true);

        return $negated ? $query->whereNot($related) : $query->where($related);
    }

    /**
     * The chosen project, kept only when the viewer may see it — a hand-
     * written URL must not probe projects the viewer cannot see.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function chosenProjectIds(array $values): array
    {
        return array_values(array_intersect(self::idList($values), array_keys($this->visibleProjectOptions())));
    }

    /**
     * The projects the viewer can see, for the relation filters' project
     * operators (Redmine's all_projects_values).
     *
     * @return array<int, string>
     */
    private function visibleProjectOptions(): array
    {
        return $this->visibleProjectOptions ??= Project::query()
            ->whereIn('id', $this->authorization->visibleProjectIds($this->viewer))
            ->orderBy('_lft')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Redmine's sql_for_watcher_id_field, offered only to a signed-in
     * viewer. "Me" always works; other users only match on issues of
     * projects where the viewer holds view_issue_watchers, and are only
     * offered as choices when the viewer holds it somewhere in the list.
     */
    private function watcherId(): ?FilterableField
    {
        $viewer = $this->viewer;

        if ($viewer === null) {
            return null;
        }

        return new CallbackFilter(
            'watcher_id',
            __('ウォッチャー'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values) use ($viewer): Builder {
                if ($values === []) {
                    return $query;
                }

                $ids = $this->userIds($values);
                $mine = array_values(array_intersect($ids, [$viewer->id]));
                $others = array_values(array_diff($ids, [$viewer->id]));
                $watcherProjectIds = $others === [] ? collect() : $this->projectIdsWith('view_issue_watchers');
                $projectColumn = $query->qualifyColumn('project_id');

                $watching = fn (Builder $watchers) => $watchers->where(function (Builder $who) use ($mine, $others, $watcherProjectIds, $projectColumn): void {
                    $who->whereRaw('1 = 0');

                    if ($mine !== []) {
                        $who->orWhereIn('watchers.user_id', $mine);
                    }

                    if ($others !== [] && $watcherProjectIds->isNotEmpty()) {
                        $who->orWhere(fn (Builder $permitted) => $permitted->whereIn('watchers.user_id', $others)->whereIn($projectColumn, $watcherProjectIds));
                    }
                });

                return self::isNegative($operator)
                    ? $query->whereDoesntHave('watchers', $watching)
                    : $query->whereHas('watchers', $watching);
            },
            fn () => ['me' => __('<< 自分 >>')] + ($this->projectIdsWith('view_issue_watchers')->isNotEmpty() ? $this->userOptions() : []),
        );
    }

    /**
     * Redmine's sql_for_updated_by_field: someone wrote one of the
     * journals the viewer may read.
     */
    private function updatedBy(): FilterableField
    {
        return new CallbackFilter(
            'updated_by',
            __('更新者'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $ids = $this->userIds($values);
                $journal = fn (QueryBuilder $journals) => $this->readableJournals($journals, $query)->whereIn('journals.user_id', $ids);

                return self::isNegative($operator) ? $query->whereNotExists($journal) : $query->whereExists($journal);
            },
            fn () => $this->userOptionsWithMe(),
        );
    }

    /**
     * Redmine's sql_for_last_updated_by_field: the author of the newest
     * journal the viewer may read.
     */
    private function lastUpdatedBy(): FilterableField
    {
        return new CallbackFilter(
            'last_updated_by',
            __('最終更新者'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $ids = $this->userIds($values);
                $journal = fn (QueryBuilder $journals) => $this->readableJournals($journals, $query, 'last_journals')
                    ->whereIn('last_journals.user_id', $ids)
                    ->where('last_journals.id', '=', fn (QueryBuilder $newest) => $this->readableJournals($newest, $query)->select($newest->raw('MAX(journals.id)')));

                return self::isNegative($operator) ? $query->whereNotExists($journal) : $query->whereExists($journal);
            },
            fn () => $this->userOptionsWithMe(),
        );
    }

    /**
     * Redmine's sql_for_member_of_group_field: the assignee belongs to
     * one of the groups (none/any: to no group / to some group). Only
     * groups the viewer can see are offered or honoured.
     */
    private function memberOfGroup(): FilterableField
    {
        return new CallbackFilter(
            'member_of_group',
            __('担当者のグループ'),
            FilterFieldType::Select,
            [...self::userOperators(), FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                $column = $query->qualifyColumn('assigned_to_id');

                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $groupIds = in_array($operator, [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], true)
                    ? null
                    : array_values(array_intersect(self::idList($values), array_keys($this->visibleGroupOptions())));

                return self::whereUserInGroups($query, $column, $groupIds, in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn, FilterOperator::IsEmpty], true));
            },
            fn () => $this->visibleGroupOptions(),
        );
    }

    /**
     * Redmine's sql_for_assigned_to_role_field: the assignee holds one of
     * the roles in the issue's project (none/any: is not / is a member of
     * it at all). A group membership counts for its users.
     */
    private function assignedToRole(): FilterableField
    {
        return new CallbackFilter(
            'assigned_to_role',
            __('担当者のロール'),
            FilterFieldType::Select,
            [...self::userOperators(), FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            fn (Builder $query, FilterOperator $operator, array $values) => $this->applyRole($query, 'assigned_to_id', $operator, $values),
            fn () => self::roleOptions(),
        );
    }

    /**
     * Redmine's author.group filter (keyed author_group here: a dot would
     * split the key in the list's URL state).
     */
    private function authorGroup(): FilterableField
    {
        return new CallbackFilter(
            'author_group',
            __('作成者のグループ'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $groupIds = array_values(array_intersect(self::idList($values), array_keys($this->visibleGroupOptions())));

                return self::whereUserInGroups($query, $query->qualifyColumn('author_id'), $groupIds, self::isNegative($operator));
            },
            fn () => $this->visibleGroupOptions(),
        );
    }

    /**
     * Redmine's author.role filter (keyed author_role here).
     */
    private function authorRole(): FilterableField
    {
        return new CallbackFilter(
            'author_role',
            __('作成者のロール'),
            FilterFieldType::Select,
            self::userOperators(),
            fn (Builder $query, FilterOperator $operator, array $values) => $this->applyRole($query, 'author_id', $operator, $values),
            fn () => self::roleOptions(),
        );
    }

    /**
     * Redmine's sql_for_attachment_field: an attachment whose file name
     * contains the text (none/any: no attachment / some attachment).
     */
    private function attachment(): FilterableField
    {
        return new CallbackFilter(
            'attachment',
            __('添付ファイル'),
            FilterFieldType::Text,
            self::textOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $matching = function (Builder $media) use ($operator, $values): void {
                    $media->where('collection_name', 'attachments');

                    if ($operator->requiresValue()) {
                        self::applyText($media, 'file_name', FilterOperator::Contains, $values);
                    }
                };

                return in_array($operator, [FilterOperator::NotContains, FilterOperator::IsEmpty], true)
                    ? $query->whereDoesntHave('media', $matching)
                    : $query->whereHas('media', $matching);
            },
        );
    }

    /**
     * Redmine's sql_for_attachment_description_field: every operator asks
     * for an attachment — one whose description contains the text, has a
     * description not containing it, has any description, or has none.
     */
    private function attachmentDescription(): FilterableField
    {
        return new CallbackFilter(
            'attachment_description',
            __('添付ファイルの説明'),
            FilterFieldType::Text,
            self::textOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $column = 'custom_properties->description';

                return $query->whereHas('media', function (Builder $media) use ($operator, $values, $column): void {
                    $media->where('collection_name', 'attachments');

                    if ($operator === FilterOperator::NotContains) {
                        self::applyText($media, $column, FilterOperator::IsNotEmpty, []);
                    }

                    self::applyText($media, $column, $operator, $values);
                });
            },
        );
    }

    /**
     * Redmine's fixed_version.due_date (keyed fixed_version_due_date): the
     * target version's due date. "None" also matches an issue without a
     * target version, as in Redmine.
     */
    private function fixedVersionDueDate(): FilterableField
    {
        return new CallbackFilter(
            'fixed_version_due_date',
            __('対象バージョンの期日'),
            FilterFieldType::Date,
            [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::InTheLastDays, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && $values === []) {
                    return $query;
                }

                $dueDate = fn (Builder $versions) => FilterOperatorApplier::apply($versions, 'versions.due_date', $operator, $values);

                return $operator === FilterOperator::IsEmpty
                    ? $query->where(fn (Builder $none) => $none->whereNull($none->qualifyColumn('fixed_version_id'))->orWhereHas('fixedVersion', $dueDate))
                    : $query->whereHas('fixedVersion', $dueDate);
            },
        );
    }

    /**
     * Redmine's fixed_version.status (keyed fixed_version_status). "Is
     * not" also matches an issue without a target version, as in Redmine.
     */
    private function fixedVersionStatus(): FilterableField
    {
        return new CallbackFilter(
            'fixed_version_status',
            __('対象バージョンのステータス'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $statuses = fn (Builder $versions) => $versions->whereIn('versions.status', array_map('strval', $values));

                return self::isNegative($operator)
                    ? $query->where(fn (Builder $other) => $other->whereNull($other->qualifyColumn('fixed_version_id'))->orWhereDoesntHave('fixedVersion', $statuses))
                    : $query->whereHas('fixedVersion', $statuses);
            },
            fn () => [
                VersionStatus::Open->value => __('オープン'),
                VersionStatus::Locked->value => __('ロック中'),
                VersionStatus::Closed->value => __('クローズ'),
            ],
        );
    }

    /**
     * Redmine's project.status (keyed project_status), offered on the
     * cross-project list and on a project that has subprojects.
     */
    private function projectStatus(): ?FilterableField
    {
        if ($this->listProject !== null && $this->listProject->_rgt - $this->listProject->_lft <= 1) {
            return null;
        }

        return new CallbackFilter(
            'project_status',
            __('プロジェクトのステータス'),
            FilterFieldType::Select,
            self::userOperators(),
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($values === []) {
                    return $query;
                }

                $statuses = fn (Builder $projects) => $projects->whereIn('projects.status', array_map('strval', $values));

                return self::isNegative($operator)
                    ? $query->whereDoesntHave('project', $statuses)
                    : $query->whereHas('project', $statuses);
            },
            fn () => [
                ProjectStatus::Active->value => __('アクティブ'),
                ProjectStatus::Closed->value => __('クローズ'),
            ],
        );
    }

    /**
     * Redmine's sql_for_spent_time_field: the hours logged on the issue
     * itself ("none"/"any": no hours / some hours). Offered only to a
     * viewer who may see time entries (on the list's project, or anywhere
     * for the cross-project list).
     */
    private function spentTime(): ?FilterableField
    {
        $allowed = $this->listProject !== null
            ? $this->authorization->can($this->viewer, 'view_time_entries', $this->listProject)
            : $this->authorization->canGlobally($this->viewer, 'view_time_entries');

        if (! $allowed) {
            return null;
        }

        return new CallbackFilter(
            'spent_time',
            __('作業時間'),
            FilterFieldType::Integer,
            [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                if ($operator->requiresValue() && ($values === [] || ($operator === FilterOperator::Between && count($values) < 2))) {
                    return $query;
                }

                $hours = 'COALESCE((SELECT SUM(time_entries.hours) FROM time_entries WHERE time_entries.issue_id = '.$query->getModel()->getQualifiedKeyName().'), 0)';
                $first = round((float) ($values[0] ?? 0), 2);

                return match ($operator) {
                    FilterOperator::IsEmpty => $query->whereRaw("{$hours} = 0"),
                    FilterOperator::IsNotEmpty => $query->whereRaw("{$hours} > 0"),
                    FilterOperator::GreaterOrEqual => $query->whereRaw("{$hours} >= ?", [$first]),
                    FilterOperator::LessOrEqual => $query->whereRaw("{$hours} <= ?", [$first]),
                    FilterOperator::Between => $query->whereRaw("{$hours} BETWEEN ? AND ?", [$first, round((float) $values[1], 2)]),
                    default => $query->whereRaw("{$hours} = ?", [$first]),
                };
            },
        );
    }

    /**
     * Redmine's sql_for_any_searchable_field: the issues the issue search
     * finds in the projects the list covers — every word ("contains"), or
     * none of them ("does not contain").
     */
    private function anySearchable(): FilterableField
    {
        return new CallbackFilter(
            'any_searchable',
            __('検索可能な項目'),
            FilterFieldType::Text,
            [FilterOperator::Contains, FilterOperator::NotContains],
            function (Builder $query, FilterOperator $operator, array $values): Builder {
                $text = trim((string) ($values[0] ?? ''));

                if ($text === '') {
                    return $query;
                }

                $negated = $operator === FilterOperator::NotContains;
                $ids = app(SearchService::class)->issueIdsMatching($this->scopeProjects(), $this->viewer, $text, allWords: ! $negated);
                $key = $query->getModel()->getQualifiedKeyName();

                if ($ids->isEmpty()) {
                    return $negated ? $query : $query->whereRaw('1 = 0');
                }

                return $negated ? $query->whereNotIn($key, $ids) : $query->whereIn($key, $ids);
            },
        );
    }

    /**
     * Redmine's sql_for_assigned_to_role_field / sql_for_author_role_field.
     * The negated operators, as in Redmine, also match an issue with
     * nobody in $userColumn.
     *
     * @param  Builder<*>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<*>
     */
    private function applyRole(Builder $query, string $userColumn, FilterOperator $operator, array $values): Builder
    {
        if ($operator->requiresValue() && $values === []) {
            return $query;
        }

        $roleIds = in_array($operator, [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], true) ? null : self::idList($values);
        $userColumn = $query->qualifyColumn($userColumn);
        $projectColumn = $query->qualifyColumn('project_id');

        $membership = function (QueryBuilder $members) use ($userColumn, $projectColumn, $roleIds): void {
            $members->select($members->raw('1'))
                ->from('members')
                ->whereColumn('members.project_id', $projectColumn)
                ->where(fn (QueryBuilder $principal) => $principal->whereColumn('members.user_id', $userColumn)
                    ->orWhereIn('members.group_id', fn (QueryBuilder $groups) => $groups->select('group_user.group_id')->from('group_user')->whereColumn('group_user.user_id', $userColumn)));

            if ($roleIds !== null) {
                $members->whereExists(fn (QueryBuilder $memberRoles) => $memberRoles->select($memberRoles->raw('1'))
                    ->from('member_roles')
                    ->whereColumn('member_roles.member_id', 'members.id')
                    ->whereIn('member_roles.role_id', $roleIds));
            }
        };

        if (! in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn, FilterOperator::IsEmpty], true)) {
            return $query->whereExists($membership);
        }

        return $query->where(fn (Builder $outside) => $outside->whereNull($userColumn)->orWhereNotExists($membership));
    }

    /**
     * The users in $column belong to one of $groupIds (null: to any
     * group), or, negated, the column is empty or they belong to none.
     *
     * @param  Builder<*>  $query
     * @param  ?array<int, int>  $groupIds
     * @return Builder<*>
     */
    private static function whereUserInGroups(Builder $query, string $column, ?array $groupIds, bool $negated): Builder
    {
        $groupUsers = fn (QueryBuilder $members) => $members->select('group_user.user_id')
            ->from('group_user')
            ->when($groupIds !== null, fn (QueryBuilder $chosen) => $chosen->whereIn('group_user.group_id', $groupIds ?? []));

        return $negated
            ? $query->where(fn (Builder $outside) => $outside->whereNull($column)->orWhereNotIn($column, $groupUsers))
            : $query->whereIn($column, $groupUsers);
    }

    /**
     * User ids from filter values, "me" standing for the viewer.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function userIds(array $values): array
    {
        return array_values(array_unique(array_map(
            fn ($value) => $value === 'me' ? (int) $this->viewer?->id : (int) $value,
            $values,
        )));
    }

    /**
     * The members of the projects the list covers.
     *
     * @return array<int|string, string>
     */
    private function userOptions(): array
    {
        return $this->scopeProjects()
            ->flatMap(fn (Project $project) => $project->users)
            ->unique('id')
            ->sortBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function userOptionsWithMe(): array
    {
        return ($this->viewer !== null ? ['me' => __('<< 自分 >>')] : []) + $this->userOptions();
    }

    /**
     * The groups the viewer may see (Redmine's Group.visible): every group
     * for someone who sees users site-wide, otherwise the groups that are
     * members of a project the viewer can see.
     *
     * @return array<int, string>
     */
    private function visibleGroupOptions(): array
    {
        if ($this->visibleGroupOptions !== null) {
            return $this->visibleGroupOptions;
        }

        $groups = Group::query()->orderBy('name');

        if (! $this->authorization->hasSiteWideUserVisibility($this->viewer)) {
            $groups->whereIn('id', Member::query()->select('group_id')->whereNotNull('group_id')->whereIn('project_id', $this->authorization->visibleProjectIds($this->viewer)));
        }

        return $this->visibleGroupOptions = $groups->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private static function roleOptions(): array
    {
        return Role::query()->givable()->pluck('name', 'id')->all();
    }

    private static function isNegative(FilterOperator $operator): bool
    {
        return in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true);
    }

    /**
     * Journals of the outer query's issue that the viewer may read —
     * Redmine's Journal.visible_notes_condition: public notes, the
     * viewer's own private notes, and private notes in projects where the
     * viewer holds view_private_notes.
     *
     * @param  Builder<*>  $issues
     */
    private function readableJournals(QueryBuilder $journals, Builder $issues, string $alias = 'journals'): QueryBuilder
    {
        $journals->select($journals->raw('1'))
            ->from($alias === 'journals' ? 'journals' : "journals as {$alias}")
            ->whereColumn("{$alias}.issue_id", $issues->getModel()->getQualifiedKeyName());

        if ($this->viewer?->is_admin) {
            return $journals;
        }

        $privateNoteProjectIds = $this->projectIdsWith('view_private_notes');
        $projectColumn = $issues->qualifyColumn('project_id');

        return $journals->where(function (QueryBuilder $visible) use ($privateNoteProjectIds, $projectColumn, $alias): void {
            $visible->where("{$alias}.private_notes", false);

            if ($this->viewer !== null) {
                $visible->orWhere("{$alias}.user_id", $this->viewer->id);
            }

            if ($privateNoteProjectIds->isNotEmpty()) {
                $visible->orWhereIn($projectColumn, $privateNoteProjectIds);
            }
        });
    }

    /**
     * The ids of the projects the list covers in which the viewer holds
     * $permission.
     *
     * @return Collection<int, int>
     */
    private function projectIdsWith(string $permission): Collection
    {
        return $this->projectIdsByPermission[$permission] ??= $this->scopeProjects()
            ->filter(fn (Project $project) => $this->authorization->can($this->viewer, $permission, $project))
            ->pluck('id')
            ->values();
    }

    /**
     * @return Collection<int, Project>
     */
    private function scopeProjects(): Collection
    {
        return $this->resolvedScopeProjects ??= ($this->scopeProjects)();
    }

    /**
     * Text matching as Redmine's :text filters: "none" and "any" treat an
     * empty string like a missing value.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  array<int, mixed>  $values
     */
    private static function applyText(Builder|QueryBuilder $query, string $column, FilterOperator $operator, array $values): void
    {
        match ($operator) {
            FilterOperator::IsEmpty => $query->where(fn ($blank) => $blank->whereNull($column)->orWhere($column, '')),
            FilterOperator::IsNotEmpty => $query->whereNotNull($column)->where($column, '<>', ''),
            default => $values === [] ? $query : $query->where(
                $column,
                ($operator === FilterOperator::NotContains ? 'not ' : '').($query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like'),
                '%'.addcslashes((string) $values[0], '%_\\').'%',
            ),
        };
    }

    /**
     * Issues below any of $ancestorIds in the tree, however deep.
     *
     * @param  Builder<*>  $query
     * @param  array<int, int>  $ancestorIds
     * @return Builder<*>
     */
    private static function whereDescendantOf(Builder $query, array $ancestorIds): Builder
    {
        $table = $query->getModel()->getTable();
        $placeholders = implode(', ', array_fill(0, count($ancestorIds), '?'));

        return $query->whereRaw(
            "{$query->getModel()->getQualifiedKeyName()} IN (WITH RECURSIVE tree_descendants AS ("
            ."SELECT id FROM {$table} WHERE parent_id IN ({$placeholders})"
            ." UNION SELECT child.id FROM {$table} child INNER JOIN tree_descendants ON child.parent_id = tree_descendants.id"
            .') SELECT id FROM tree_descendants)',
            $ancestorIds,
        );
    }

    /**
     * The ids in a value like Redmine's "1, 3 5": every run of digits.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private static function idList(array $values): array
    {
        preg_match_all('/\d+/', implode(',', array_map(fn ($value) => (string) $value, $values)), $matches);

        return array_values(array_unique(array_map('intval', $matches[0])));
    }

    /**
     * @return array<int, FilterOperator>
     */
    private static function textOperators(): array
    {
        return [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    /**
     * Redmine's :list operators on people (= and !), plus their
     * multi-value forms.
     *
     * @return array<int, FilterOperator>
     */
    private static function userOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
    }

    /**
     * Redmine's :tree operators (=, ~, !*, *).
     *
     * @return array<int, FilterOperator>
     */
    private static function treeOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::Contains, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }
}
