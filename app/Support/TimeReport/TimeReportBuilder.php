<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use App\Models\TimeEntry;
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
     * @param  array<int, TimeReportAxis>  $criteria  Deduped, capped to 3 by the caller — matching Redmine's `@criteria[0, 3]`.
     */
    public function build(Builder $baseQuery, array $criteria, TimeReportPeriod $period): TimeReportTable
    {
        if ($criteria === []) {
            return TimeReportTable::empty();
        }

        // Each axis is selected under its own alias (axis_0, axis_1, ...) so a
        // custom field's value column never collides with another axis' name.
        $selects = [];
        $groups = [];

        foreach ($criteria as $index => $axis) {
            $selects[] = "{$axis->expression} as axis_{$index}";
            $groups[] = $axis->expression;
        }

        $query = (clone $baseQuery)
            ->when(
                collect($criteria)->contains(fn (TimeReportAxis $axis) => $axis->needsIssueJoin),
                fn (Builder $q) => $q->leftJoin('issues', 'time_entries.issue_id', '=', 'issues.id'),
            );

        foreach ($criteria as $axis) {
            if ($axis->join !== null) {
                ($axis->join)($query);
            }
        }

        $query = $query
            ->groupBy([...$groups, 'time_entries.spent_on'])
            ->selectRaw(implode(', ', $selects).', time_entries.spent_on as spent_on, SUM(time_entries.hours) as hours');

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
            $values = array_map(fn (int $index) => $row->{"axis_{$index}"}, array_keys($criteria));
            $rowKey = implode('|', array_map(fn ($v) => is_bool($v) ? (int) $v : ($v ?? ''), $values));
            $periodKey = $period->keyFor(Carbon::parse($row->spent_on));

            if (! isset($buckets[$rowKey])) {
                $buckets[$rowKey] = [
                    'labels' => array_map(
                        fn (TimeReportAxis $axis, $v) => $labelResolvers[$axis->key][$v ?? ''] ?? '(なし)',
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
     * Batch-resolves display labels for every distinct value each axis
     * actually returned, one query per axis rather than N+1 per row.
     *
     * @param  array<int, TimeReportAxis>  $criteria
     * @param  Collection<int, object>  $rawRows
     * @return array<string, array<int|string, string>> keyed by axis key => [rawValue => label]
     */
    private function labelResolvers(array $criteria, Collection $rawRows): array
    {
        $resolvers = [];

        foreach ($criteria as $index => $axis) {
            $values = $rawRows->pluck("axis_{$index}")->filter(fn ($value) => $value !== null && $value !== '')->unique()->values();

            $resolvers[$axis->key] = ($axis->labels)($values);
        }

        return $resolvers;
    }
}
