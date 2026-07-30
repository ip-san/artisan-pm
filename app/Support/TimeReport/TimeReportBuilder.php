<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pivots a filtered TimeEntry query across up to 3 row criteria x one
 * period column axis — mirrors Redmine::Helpers::TimeReport
 * (lib/redmine/helpers/time_report.rb): group by the selected criteria
 * plus the raw spent_on date in SQL (one SUM(hours) query), then bucket
 * each row's date into the selected period (year/month/week/day) in PHP,
 * same two-phase approach Redmine itself uses (SQL groups by exact date,
 * Ruby buckets into the coarser period afterwards).
 */
final class TimeReportBuilder
{
    /** Redmine's own period-column cap ("100 columns max"). */
    private const int MAX_PERIODS = 100;

    /**
     * @param  Builder<TimeEntry>  $baseQuery  Already scoped/filtered (project, visibility, etc.) — this method only adds grouping/joins on top.
     * @param  array<int, TimeReportCriterion>  $criteria  Deduped, capped to 3 by the caller — matching Redmine's `@criteria[0, 3]`.
     */
    public function build(Builder $baseQuery, array $criteria, TimeReportPeriod $period): TimeReportTable
    {
        if ($criteria === []) {
            return TimeReportTable::empty();
        }

        // Every criterion's underlying column has a distinct unqualified
        // name (status_id, fixed_version_id, category_id, user_id,
        // tracker_id, activity_id, issue_id) — selecting them table-qualified
        // but unaliased is safe since no driver ever has to disambiguate two
        // same-named columns here, so each comes back keyed by that bare name.
        $columns = array_map(fn (TimeReportCriterion $c) => $c->column(), $criteria);

        $query = (clone $baseQuery)
            ->when(
                TimeReportCriterion::requiresIssueJoin($criteria),
                fn (Builder $q) => $q->leftJoin('issues', 'time_entries.issue_id', '=', 'issues.id'),
            )
            ->groupBy([...$columns, 'time_entries.spent_on'])
            ->selectRaw(implode(', ', $columns).', time_entries.spent_on as spent_on, SUM(time_entries.hours) as hours');

        /** @var Collection<int, object> $rawRows */
        $rawRows = $query->get();

        if ($rawRows->isEmpty()) {
            return TimeReportTable::empty();
        }

        $periods = $this->buildPeriods($rawRows, $period);
        $labelResolvers = $this->labelResolvers($criteria, $rawRows);

        /** @var array<string, array{labels: array<int, string>, cells: array<string, float>, total: float}> $buckets */
        $buckets = [];

        foreach ($rawRows as $row) {
            $values = array_map(fn (TimeReportCriterion $c) => $row->{$this->shortColumn($c)}, $criteria);
            $rowKey = implode('|', array_map(fn ($v) => $v ?? '', $values));
            $periodKey = $period->keyFor(Carbon::parse($row->spent_on));

            if (! isset($buckets[$rowKey])) {
                $buckets[$rowKey] = [
                    'labels' => array_map(
                        fn (TimeReportCriterion $c, $v) => $labelResolvers[$c->value][$v ?? ''] ?? '(なし)',
                        $criteria,
                        $values,
                    ),
                    'cells' => [],
                    'total' => 0.0,
                ];
            }

            $hours = (float) $row->hours;
            $buckets[$rowKey]['cells'][$periodKey] = ($buckets[$rowKey]['cells'][$periodKey] ?? 0.0) + $hours;
            $buckets[$rowKey]['total'] += $hours;
        }

        uasort($buckets, fn ($a, $b) => implode(' ', $a['labels']) <=> implode(' ', $b['labels']));

        $columnTotals = array_fill_keys(collect($periods)->pluck('key')->all(), 0.0);
        $grandTotal = 0.0;

        foreach ($buckets as $bucket) {
            foreach ($bucket['cells'] as $key => $hours) {
                $columnTotals[$key] = ($columnTotals[$key] ?? 0.0) + $hours;
            }
            $grandTotal += $bucket['total'];
        }

        return new TimeReportTable(
            periods: $periods,
            rows: array_values($buckets),
            columnTotals: $columnTotals,
            grandTotal: $grandTotal,
        );
    }

    /**
     * The unqualified column name a criterion's grouped value comes back
     * under (e.g. 'issues.status_id' => 'status_id').
     */
    private function shortColumn(TimeReportCriterion $criterion): string
    {
        $column = $criterion->column();

        return str_contains($column, '.') ? substr($column, strpos($column, '.') + 1) : $column;
    }

    /**
     * @param  Collection<int, object>  $rawRows
     * @return array<int, array{key: string, label: string}>
     */
    private function buildPeriods(Collection $rawRows, TimeReportPeriod $period): array
    {
        $dates = $rawRows->pluck('spent_on')->map(fn ($d) => Carbon::parse($d));
        $from = $period->startOf($dates->min());
        $to = $dates->max();

        $periods = [];
        $cursor = $from;

        while ($cursor->lte($to) && count($periods) < self::MAX_PERIODS) {
            $key = $period->keyFor($cursor);
            $periods[] = ['key' => $key, 'label' => $period->labelFor($key)];
            $cursor = $period->next($cursor);
        }

        return $periods;
    }

    /**
     * Batch-resolves display labels for every distinct value each
     * criterion actually returned, one query per criterion rather than
     * N+1 per row.
     *
     * @param  array<int, TimeReportCriterion>  $criteria
     * @param  Collection<int, object>  $rawRows
     * @return array<string, array<int|string, string>> keyed by criterion value => [rawValue => label]
     */
    private function labelResolvers(array $criteria, Collection $rawRows): array
    {
        $resolvers = [];

        foreach ($criteria as $criterion) {
            $ids = $rawRows->pluck($this->shortColumn($criterion))->filter()->unique()->values();

            $resolvers[$criterion->value] = match ($criterion) {
                TimeReportCriterion::Status => IssueStatus::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::Version => Version::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::Category => IssueCategory::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::User => User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::Tracker => Tracker::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::Activity => Enumeration::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                TimeReportCriterion::Issue => Issue::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Issue $issue) => [$issue->id => "#{$issue->id} {$issue->subject}"])->all(),
            };
        }

        return $resolvers;
    }
}
