<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class TimeEntryActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'time-entry';
    }

    public function label(): string
    {
        return '工数';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_time_entries', $project)) {
            return collect();
        }

        return TimeEntry::query()
            ->where('project_id', $project->id)
            ->whereBetween('spent_on', [$from, $to])
            ->with(['activity', 'issue', 'user'])
            ->get()
            ->map(fn (TimeEntry $entry) => new ActivityEntry(
                type: $this->type(),
                title: "{$entry->hours}時間 ({$entry->activity->name})".($entry->issue ? " — #{$entry->issue->id} {$entry->issue->subject}" : ''),
                url: $entry->issue ? route('issues.show', [$project, $entry->issue]) : route('time-entries.index', $project),
                authorName: $entry->user->name,
                occurredAt: $entry->spent_on,
            ));
    }
}
