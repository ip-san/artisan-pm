<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\MultiProjectActivityProvider;
use App\Support\Activity\OffByDefault;
use App\Support\Authorization\AuthorizationService;
use App\Support\Format\DateTimes;
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
        return __('工数');
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

        // spent_on is a day, not a moment: the range's days as the viewer
        // sees them, and each entry dated at the start of its day in their zone
        // so grouping by the viewer's day keeps it on its own date.
        $zone = DateTimes::timeZone($viewer);

        $entries = TimeEntry::query()
            ->whereIn('project_id', $projects->keys())
            ->whereBetween('spent_on', [$from->copy()->setTimezone($zone)->toDateString(), $to->copy()->setTimezone($zone)->toDateString()])
            ->with(['activity', 'issue.project', 'user'])
            ->get();

        // Redmine's TimeEntry event title names the issue only when it is
        // visible, falling back to the project otherwise.
        $visibleIssueIds = Issue::filterVisible($entries->pluck('issue')->filter()->unique('id')->values(), $viewer)->pluck('id')->flip();

        return $entries
            ->map(fn (TimeEntry $entry) => new ActivityEntry(
                type: $this->type(),
                title: __(':hours時間 (:activity)', ['hours' => $entry->hours, 'activity' => $entry->activity->name]).($entry->issue && $visibleIssueIds->has($entry->issue->id) ? " — #{$entry->issue->id} {$entry->issue->subject}" : ''),
                url: $entry->issue && $visibleIssueIds->has($entry->issue->id) ? route('issues.show', [$projects[$entry->project_id], $entry->issue]) : route('time-entries.index', $projects[$entry->project_id]),
                authorName: $entry->user->displayName(),
                occurredAt: Carbon::parse($entry->spent_on->toDateString(), $zone),
                authorId: $entry->user_id,
            ));
    }
}
