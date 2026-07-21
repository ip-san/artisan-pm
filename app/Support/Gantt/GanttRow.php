<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use Illuminate\Support\Carbon;

/**
 * One issue's Gantt-relevant data, hydrated from a raw recursive-CTE query
 * result row (see GanttService) — Eloquent's own casting doesn't apply to
 * DB::select() output, so dates arrive as strings and are parsed here.
 */
final readonly class GanttRow
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $subject,
        public ?Carbon $startDate,
        public ?Carbon $dueDate,
        public int $doneRatio,
        public string $trackerName,
        public string $statusName,
        public bool $isClosed,
        public int $depth,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            parentId: $row->parent_id !== null ? (int) $row->parent_id : null,
            subject: $row->subject,
            startDate: $row->start_date !== null ? Carbon::parse($row->start_date) : null,
            dueDate: $row->due_date !== null ? Carbon::parse($row->due_date) : null,
            doneRatio: (int) $row->done_ratio,
            trackerName: $row->tracker_name,
            statusName: $row->status_name,
            isClosed: (bool) $row->is_closed,
            depth: (int) $row->depth,
        );
    }

    public function hasDateRange(): bool
    {
        return $this->startDate !== null && $this->dueDate !== null;
    }
}
