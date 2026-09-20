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

    /**
     * The extra fields a row can show after the title, in Redmine's
     * "columns" setting; status alone is what the block showed before.
     *
     * @var array<string, string>
     */
    public const array COLUMNS = [
        'project' => 'プロジェクト',
        'tracker' => 'トラッカー',
        'status' => 'ステータス',
        'priority' => '優先度',
        'assigned_to' => '担当者',
        'author' => '作成者',
        'start_date' => '開始日',
        'due_date' => '期日',
        'updated_at' => '更新日',
    ];

    /**
     * Sort keys the block's own "sort" setting accepts, as `key:direction`.
     *
     * @var array<string, string>
     */
    public const array SORTS = [
        'id' => 'ID',
        'subject' => '題名',
        'start_date' => '開始日',
        'due_date' => '期日',
        'created_at' => '作成日',
        'updated_at' => '更新日',
    ];

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
     * @param  array<string, mixed>  $settings  the block's own columns/sort (see normalizeSettings())
     * @return Collection<int, DashboardBlockRow>
     */
    public function rows(?Query $savedQuery, User $user, array $settings = []): Collection
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
            ->with(['project', 'tracker', 'status', 'priority', 'assignedTo', 'author']);

        $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project));
        $builder = $engine->applyFilters($builder, $savedQuery->filters);

        $settings = self::normalizeSettings($settings);
        $sortCriteria = $savedQuery->sort_criteria ?? [];

        if (isset($settings['sort'])) {
            [$column, $direction] = explode(':', $settings['sort']);
            $builder->orderBy($column, $direction)->orderBy('id', $direction);
        } elseif ($sortCriteria !== []) {
            $builder = $engine->applySort($builder, $sortCriteria);
        } else {
            $builder->orderByDesc('id');
        }

        $columns = $settings['columns'] ?? ['status'];

        return $builder
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: collect($columns)->map(fn (string $column) => $this->columnValue($issue, $column))->filter()->join(' / ') ?: null,
            ));
    }

    /**
     * Keeps only known columns (in the canonical order) and a known sort;
     * anything else is dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array{columns?: array<int, string>, sort?: string}
     */
    public static function normalizeSettings(array $input): array
    {
        $settings = [];
        $columns = array_values(array_intersect(array_keys(self::COLUMNS), (array) ($input['columns'] ?? [])));

        if ($columns !== []) {
            $settings['columns'] = $columns;
        }

        $sort = (string) ($input['sort'] ?? '');
        [$column, $direction] = array_pad(explode(':', $sort, 2), 2, '');

        if (array_key_exists($column, self::SORTS) && in_array($direction, ['asc', 'desc'], true)) {
            $settings['sort'] = $sort;
        }

        return $settings;
    }

    private function columnValue(Issue $issue, string $column): string
    {
        return match ($column) {
            'project' => $issue->project->name,
            'tracker' => $issue->tracker->name,
            'status' => $issue->status->name,
            'priority' => (string) $issue->priority?->name,
            'assigned_to' => (string) $issue->assignedTo?->displayName(),
            'author' => (string) $issue->author?->displayName(),
            'start_date' => (string) $issue->start_date?->toDateString(),
            'due_date' => (string) $issue->due_date?->toDateString(),
            'updated_at' => $issue->updated_at->toDateString(),
            default => '',
        };
    }
}
