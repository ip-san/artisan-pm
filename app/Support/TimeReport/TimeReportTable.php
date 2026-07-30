<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

/**
 * The rendered result of TimeReportBuilder::build() — a pivot of row
 * (criteria combination) x column (period) x summed hours, plus row/column
 * totals and a grand total. Immutable, holds only display-ready data so
 * the Volt component doesn't need to know about the underlying query.
 */
final readonly class TimeReportTable
{
    /**
     * @param  array<int, array{key: string, label: string}>  $periods
     * @param  array<int, array{labels: array<int, string>, cells: array<string, float>, total: float}>  $rows
     * @param  array<string, float>  $columnTotals
     */
    public function __construct(
        public array $periods,
        public array $rows,
        public array $columnTotals,
        public float $grandTotal,
    ) {}

    public static function empty(): self
    {
        return new self(periods: [], rows: [], columnTotals: [], grandTotal: 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
