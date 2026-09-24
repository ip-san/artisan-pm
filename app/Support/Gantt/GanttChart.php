<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use App\Enums\IssueRelationType;
use App\Models\IssueRelation;
use App\Models\Version;
use App\Support\Format\DateTimes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The date geometry of one Gantt chart: its date range (cut to Redmine's
 * gantt_months_limit), month bands, and where each bar, milestone and
 * relation line falls as a percentage of the chart's width. Shared by the
 * project chart, the cross-project chart and their PDF/PNG exports so all
 * four agree on where things are.
 */
final class GanttChart
{
    public readonly ?Carbon $rangeStart;

    public readonly ?Carbon $rangeEnd;

    public readonly bool $monthsTruncated;

    /**
     * @param  Collection<int, GanttRow>  $rows  the issue rows drawn
     * @param  Collection<int, Version>  $versions  the milestones drawn
     * @param  int  $monthsLimit  Redmine's gantt_months_limit (0 = unlimited)
     */
    public function __construct(Collection $rows, Collection $versions, int $monthsLimit)
    {
        $this->rangeStart = $rows->pluck('startDate')->filter()->min();
        $issuesEnd = $rows->pluck('dueDate')->filter()->max();

        // A chart with milestones but no dated issues has no range: the
        // empty state is shown instead of a chart of milestones only.
        if ($issuesEnd === null || $this->rangeStart === null) {
            $this->rangeEnd = null;
            $this->monthsTruncated = false;

            return;
        }

        $versionsEnd = $versions->pluck('due_date')->filter()->max();
        $end = collect([$issuesEnd, $versionsEnd])->filter()->max();

        if ($monthsLimit > 0) {
            $end = $end->min($this->rangeStart->copy()->addMonthsNoOverflow($monthsLimit)->subDay());
        }

        $this->rangeEnd = $end;
        $this->monthsTruncated = $issuesEnd->gt($end);
    }

    public function isEmpty(): bool
    {
        return $this->rangeStart === null || $this->rangeEnd === null;
    }

    public function totalDays(): int
    {
        if ($this->isEmpty()) {
            return 0;
        }

        return max(1, (int) $this->rangeStart->diffInDays($this->rangeEnd) + 1);
    }

    /**
     * @return array<int, array{label: string, leftPercent: float, widthPercent: float, start: Carbon, end: Carbon}>
     */
    public function monthBands(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $bands = [];
        $cursor = $this->rangeStart->copy()->startOfMonth();

        while ($cursor->lte($this->rangeEnd)) {
            $bandStart = $cursor->max($this->rangeStart);
            $bandEnd = $cursor->copy()->endOfMonth()->min($this->rangeEnd);

            $bands[] = [
                'label' => DateTimes::month($cursor),
                'leftPercent' => $this->percentFromStart($bandStart),
                'widthPercent' => $this->percentWidth($bandStart, $bandEnd),
                'start' => $bandStart->copy(),
                'end' => $bandEnd->copy(),
            ];

            $cursor->addMonthNoOverflow();
        }

        return $bands;
    }

    public function barLeftPercent(GanttRow $row): float
    {
        return $this->percentFromStart($row->startDate ?? throw new LogicException('Gantt row is missing a start date.'));
    }

    public function barWidthPercent(GanttRow $row): float
    {
        return $this->percentWidth(
            $row->startDate ?? throw new LogicException('Gantt row is missing a start date.'),
            $row->dueDate ?? throw new LogicException('Gantt row is missing a due date.'),
        );
    }

    public function versionMarkerLeftPercent(Version $version): float
    {
        return $this->percentFromStart($version->due_date ?? throw new LogicException('Version is missing a due date.'));
    }

    public function percentFromStart(Carbon $date): float
    {
        return min(100.0, $this->rangeStart->diffInDays($date) / $this->totalDays() * 100);
    }

    /**
     * A bar that runs past the (possibly month-limited) end of the chart is
     * clipped there instead of overflowing it.
     */
    public function percentWidth(Carbon $from, Carbon $to): float
    {
        $to = $to->min($this->rangeEnd);

        return max(0.0, min(100.0 - $this->percentFromStart($from), (($from->diffInDays($to) + 1) / $this->totalDays()) * 100));
    }

    /**
     * Connector lines between related issues drawn on the chart — Redmine's
     * Gantt#relations (only precedes/blocks, its DRAW_TYPES; a line is
     * skipped if either end has no date range, since there's no bar edge to
     * anchor it to). x is a percentage of the timeline's width, y the pixel
     * middle of the row at $rowIndexById[id] for rows $rowHeight tall.
     *
     * @param  Collection<int, IssueRelation>  $relations
     * @param  Collection<int, GanttRow>  $rows
     * @param  array<int, int>  $rowIndexById  issue id → the row's position among every row drawn (project and milestone rows included)
     * @return array<int, array{x1: float, y1: float, x2: float, y2: float, color: string, type: string}>
     */
    public function relationLines(Collection $relations, Collection $rows, array $rowIndexById, int $rowHeight): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $rowsById = $rows->keyBy('id');
        $lines = [];

        foreach ($relations as $relation) {
            $from = $rowsById->get($relation->issue_from_id);
            $to = $rowsById->get($relation->issue_to_id);

            if ($from === null || $to === null || ! $from->hasDateRange() || ! $to->hasDateRange()) {
                continue;
            }

            $lines[] = [
                'x1' => $this->barLeftPercent($from) + $this->barWidthPercent($from),
                'y1' => ($rowIndexById[$from->id] + 0.5) * $rowHeight,
                'x2' => $this->barLeftPercent($to),
                'y2' => ($rowIndexById[$to->id] + 0.5) * $rowHeight,
                'color' => $relation->relation_type === IssueRelationType::Blocks ? '#fa5252' : '#228be6',
                'type' => $relation->relation_type->value,
            ];
        }

        return $lines;
    }
}
