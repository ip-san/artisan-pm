<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Query\CallbackFilter;
use App\Support\Query\FilterableField;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Redmine's `display_subprojects_issues`: whether a project's issue list and
 * totals also cover its subprojects. The projects in scope are the project
 * itself plus, with the setting on, every descendant the viewer may look at.
 * Off by default here (Redmine's default is on), so an existing installation
 * keeps the per-project lists it has always shown.
 */
final class SubprojectScope
{
    /**
     * The filter key of Redmine's subproject_id (a :list_subprojects
     * filter read by Query#project_statement rather than applied as a
     * condition of its own).
     */
    public const string FILTER_KEY = 'subproject_id';

    public static function enabled(): bool
    {
        return (bool) Setting::get('display_subprojects_issues', false);
    }

    /**
     * The projects a project's issue list covers. A subproject_id filter
     * in $filters takes in every subproject whatever the setting (its
     * condition then narrows the list), as project_statement does.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Project>
     */
    public static function projectsForIssues(Project $project, ?User $user, array $filters = []): Collection
    {
        return self::projects($project, self::issueViewer($user), fn () => self::takesInSubprojects($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Project>
     */
    public static function projectsForTimeEntries(Project $project, ?User $user, array $filters = []): Collection
    {
        return self::projects($project, self::timeEntryViewer($user), fn () => self::takesInSubprojects($filters));
    }

    /**
     * The project plus, with $withSubprojects, the subprojects whose issues
     * $user may look at — for screens with their own "subprojects" switch
     * (Redmine's roadmap with_subprojects).
     *
     * @return Collection<int, Project>
     */
    public static function projectsForIssuesWhen(Project $project, ?User $user, bool $withSubprojects): Collection
    {
        return self::projects($project, self::issueViewer($user), $withSubprojects);
    }

    /**
     * Redmine's subproject_id filter for a project that has subprojects;
     * null for a leaf project. Its choices and its conditions only ever
     * involve the subprojects $user may look at (issues, or time entries
     * with $forTimeEntries), and it only narrows the list: the widening is
     * left to projectsForIssues()/projectsForTimeEntries().
     */
    public static function filter(Project $project, ?User $user, bool $forTimeEntries = false): ?FilterableField
    {
        if ($project->_rgt - $project->_lft <= 1) {
            return null;
        }

        $subprojects = null;
        $resolve = function () use (&$subprojects, $project, $user, $forTimeEntries): Collection {
            return $subprojects ??= self::projects($project, $forTimeEntries ? self::timeEntryViewer($user) : self::issueViewer($user), true)
                ->reject(fn (Project $candidate) => $candidate->is($project))
                ->values();
        };

        return new CallbackFilter(
            self::FILTER_KEY,
            __('サブプロジェクト'),
            FilterFieldType::Select,
            self::operators(),
            function (Builder $query, FilterOperator $operator, array $values) use ($project, $resolve): Builder {
                $subprojectIds = $resolve()->pluck('id')->all();
                $chosen = array_map('intval', $values);

                $kept = match ($operator) {
                    FilterOperator::Equals, FilterOperator::In => array_intersect($subprojectIds, $chosen),
                    FilterOperator::NotEquals, FilterOperator::NotIn => array_diff($subprojectIds, $chosen),
                    FilterOperator::IsEmpty => [],
                    default => $subprojectIds,
                };

                return $query->whereIn($query->qualifyColumn('project_id'), [$project->id, ...array_values($kept)]);
            },
            fn () => $resolve()->pluck('name', 'id')->all(),
        );
    }

    /**
     * Whether the list takes in the subprojects: with a usable
     * subproject_id filter, or else with the setting on.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function takesInSubprojects(array $filters): bool
    {
        $operator = is_array($filters[self::FILTER_KEY] ?? null) && is_string($filters[self::FILTER_KEY]['operator'] ?? null)
            ? FilterOperator::tryFrom($filters[self::FILTER_KEY]['operator'])
            : null;

        return ($operator !== null && in_array($operator, self::operators(), true)) || self::enabled();
    }

    /**
     * Redmine's :list_subprojects operators ("=", "!", "!*" main project
     * only, "*" every subproject), with the multi-value forms of = and !.
     *
     * @return array<int, FilterOperator>
     */
    private static function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
    }

    /**
     * @return Closure(Project): bool
     */
    private static function issueViewer(?User $user): Closure
    {
        return fn (Project $candidate) => $user?->can('viewAny', [Issue::class, $candidate]) ?? false;
    }

    /**
     * @return Closure(Project): bool
     */
    private static function timeEntryViewer(?User $user): Closure
    {
        return fn (Project $candidate) => $user?->can('viewAny', [TimeEntry::class, $candidate]) ?? false;
    }

    /**
     * @param  callable(Project): bool  $mayLook
     * @param  bool|Closure(): bool  $withSubprojects  asked only for a project that has subprojects (it may read the setting)
     * @return Collection<int, Project>
     */
    private static function projects(Project $project, callable $mayLook, bool|Closure $withSubprojects): Collection
    {
        if ($project->_rgt - $project->_lft <= 1 || ! ($withSubprojects instanceof Closure ? $withSubprojects() : $withSubprojects)) {
            return collect([$project]);
        }

        return Project::query()
            ->where('_lft', '>=', $project->_lft)
            ->where('_rgt', '<=', $project->_rgt)
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('_lft')
            ->get()
            ->filter(fn (Project $candidate) => $candidate->is($project) || $mayLook($candidate))
            ->values();
    }
}
