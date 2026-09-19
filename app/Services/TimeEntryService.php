<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TimeEntryCreated;
use App\Events\TimeEntryDeleted;
use App\Events\TimeEntryUpdated;
use App\Models\TimeEntry;
use App\Support\TimeLog\TimeLogConstraints;

/**
 * Thin wrapper around TimeEntry mutations whose only job is dispatching
 * the Created/Updated/Deleted events every write path needs (currently
 * just for webhooks) — mirrors IssueService/WikiPageService's shape.
 * Like those, this performs no authorization itself; every caller already
 * gates access before reaching here. It does enforce the `timelog_*`
 * settings (TimeLogConstraints) and throws a ValidationException when an
 * entry breaks one.
 */
final class TimeEntryService
{
    /**
     * `author_id` defaults to the signed-in user, or to the entry's own user
     * when there is none (queued imports and the like pass it explicitly).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TimeEntry
    {
        $attributes['author_id'] ??= auth()->id() ?? ($attributes['user_id'] ?? null);

        TimeLogConstraints::assertSatisfied($attributes);

        $timeEntry = TimeEntry::create($attributes);

        TimeEntryCreated::dispatch($timeEntry);

        return $timeEntry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TimeEntry $timeEntry, array $attributes): TimeEntry
    {
        TimeLogConstraints::assertSatisfied($attributes, $timeEntry);

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
