<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\Project;
use App\Models\User;
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

    /**
     * @param  Closure(): Collection<int, Project>  $scopeProjects  the projects whose issues the list covers, resolved only when a filter needs them
     */
    public function __construct(
        private readonly Closure $scopeProjects,
        private readonly ?User $viewer,
        private readonly AuthorizationService $authorization,
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
        ]));
    }

    private function description(): FilterableField
    {
        return new CallbackFilter(
            'description',
            __('説明'),
            FilterFieldType::Text,
            self::textOperators(),
            fn (Builder $query, FilterOperator $operator, array $values) => self::applyText($query, $query->qualifyColumn('description'), $operator, $values),
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
     * Journals of the outer query's issue that the viewer may read —
     * Redmine's Journal.visible_notes_condition: public notes, the
     * viewer's own private notes, and private notes in projects where the
     * viewer holds view_private_notes.
     *
     * @param  Builder<*>  $issues
     */
    private function readableJournals(QueryBuilder $journals, Builder $issues): QueryBuilder
    {
        $journals->select($journals->raw('1'))
            ->from('journals')
            ->whereColumn('journals.issue_id', $issues->getModel()->getQualifiedKeyName());

        if ($this->viewer?->is_admin) {
            return $journals;
        }

        $privateNoteProjectIds = $this->projectIdsWith('view_private_notes');
        $projectColumn = $issues->qualifyColumn('project_id');

        return $journals->where(function (QueryBuilder $visible) use ($privateNoteProjectIds, $projectColumn): void {
            $visible->where('journals.private_notes', false);

            if ($this->viewer !== null) {
                $visible->orWhere('journals.user_id', $this->viewer->id);
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
     * @template TBuilder of Builder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  array<int, mixed>  $values
     * @return TBuilder
     */
    private static function applyText(Builder|QueryBuilder $query, string $column, FilterOperator $operator, array $values): Builder|QueryBuilder
    {
        return match ($operator) {
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
     * Redmine's :tree operators (=, ~, !*, *).
     *
     * @return array<int, FilterOperator>
     */
    private static function treeOperators(): array
    {
        return [FilterOperator::Equals, FilterOperator::Contains, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }
}
