<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One row axis of the time report, whatever it groups by: a native column, the
 * project, or a custom field's value. It knows the SQL expression to group on,
 * the joins that expression needs, and how to turn the raw grouped values into
 * labels.
 */
final readonly class TimeReportAxis
{
    /**
     * @param  Closure(Builder<*>): void|null  $join  adds this axis' own join
     * @param  Closure(Collection<int, mixed>): array<int|string, string>  $labels  distinct raw values => label
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $expression,
        public bool $needsIssueJoin,
        public ?Closure $join,
        public Closure $labels,
    ) {}
}
