<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TimeEntryCreated;
use App\Events\TimeEntryDeleted;
use App\Events\TimeEntryUpdated;
use App\Models\TimeEntry;

/**
 * Thin wrapper around TimeEntry mutations whose only job is dispatching
 * the Created/Updated/Deleted events every write path needs (currently
 * just for webhooks) — mirrors IssueService/WikiPageService's shape.
 * Like those, this performs no authorization itself; every caller already
 * gates access before reaching here.
 */
final class TimeEntryService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TimeEntry
    {
        $timeEntry = TimeEntry::create($attributes);

        TimeEntryCreated::dispatch($timeEntry);

        return $timeEntry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TimeEntry $timeEntry, array $attributes): TimeEntry
    {
        $timeEntry->update($attributes);

        TimeEntryUpdated::dispatch($timeEntry);

        return $timeEntry;
    }

    /**
     * Dispatched before the row is actually removed, so listeners (e.g.
     * the webhook payload builder) see a fully intact model — matches
     * IssueService::delete()'s same ordering rationale.
     */
    public function delete(TimeEntry $timeEntry): void
    {
        TimeEntryDeleted::dispatch($timeEntry);

        $timeEntry->delete();
    }
}
