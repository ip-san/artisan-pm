<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Activity\MultiProjectActivityProvider;
use App\Support\Activity\OffByDefault;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class TimeEntryActivityProvider implements MultiProjectActivityProvider, OffByDefault
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
        return $this->entriesForProjects(collect([$project]), $viewer, $from, $to);
    }

    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_time_entries', $project))->keyBy('id');

        if ($projects->isEmpty()) {
            return collect();
        }

        return TimeEntry::query()
            ->whereIn('project_id', $projects->keys())
            ->whereBetween('spent_on', [$from, $to])
            ->with(['activity', 'issue', 'user'])
            ->get()
            ->map(fn (TimeEntry $entry) => new ActivityEntry(
                type: $this->type(),
                title: "{$entry->hours}時間 ({$entry->activity->name})".($entry->issue ? " — #{$entry->issue->id} {$entry->issue->subject}" : ''),
                url: $entry->issue ? route('issues.show', [$projects[$entry->project_id], $entry->issue]) : route('time-entries.index', $projects[$entry->project_id]),
                authorName: $entry->user->displayName(),
                occurredAt: $entry->spent_on,
                authorId: $entry->user_id,
            ));
    }
}
