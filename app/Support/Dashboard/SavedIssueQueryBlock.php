<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\Issue;
use App\Models\Query;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Support\Collection;

/**
 * My Page block backed by a saved issue query — Redmine's "issuequery"
 * block. Unlike the static blocks in the DashboardBlockRegistry, each
 * instance is parameterized by a query id, carried in the block key
 * ("issue_query:{id}"), so it lives outside the registry and the
 * dashboard resolves it by prefix instead.
 */
final class SavedIssueQueryBlock
{
    public const string KEY_PREFIX = 'issue_query:';

    private const int MAX_ROWS = 10;

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public static function keyFor(Query $query): string
    {
        return self::KEY_PREFIX.$query->id;
    }

    public static function queryIdFromKey(string $key): ?int
    {
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        return (int) substr($key, strlen(self::KEY_PREFIX));
    }

    /**
     * Runs the saved query the same way issues.index would: its stored
     * filters through the same QueryFilterEngine, on top of the same
     * per-role issue visibility scoping — so a block never shows a row
     * its owner couldn't see on the list itself. Returns empty when the
     * query is gone, its project is inaccessible, or visibility was
     * revoked since the block was added.
     *
     * @return Collection<int, DashboardBlockRow>
     */
    public function rows(?Query $savedQuery, User $user): Collection
    {
        $project = $savedQuery?->project;

        if ($savedQuery === null || $project === null
            || ! $savedQuery->visibleTo($user)
            || ! $this->authorization->can($user, 'view_issues', $project)) {
            return collect();
        }

        $builder = Issue::query()
            ->where('project_id', $project->id)
            ->visibleTo($user, $project)
            ->with(['project', 'tracker', 'status']);

        $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project));
        $builder = $engine->applyFilters($builder, $savedQuery->filters);

        $sortCriteria = $savedQuery->sort_criteria ?? [];

        if ($sortCriteria !== []) {
            $builder = $engine->applySort($builder, $sortCriteria);
        } else {
            $builder->orderByDesc('id');
        }

        return $builder
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: $issue->status->name,
            ));
    }
}
