<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

/**
 * The week ahead as a list (Redmine's calendar block): open issues in the
 * user's projects that start or are due in the next seven days, soonest first.
 * A list rather than a month grid, like the other blocks.
 */
final class CalendarBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    private const int DAYS_AHEAD = 6;

    public function key(): string
    {
        return 'calendar';
    }

    public function label(): string
    {
        return '今週のカレンダー';
    }

    public function rows(User $user): Collection
    {
        $projects = $user->projects()->get()->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))->values();
        $from = now()->startOfDay();
        $to = now()->addDays(self::DAYS_AHEAD)->endOfDay();

        return Issue::query()
            ->visibleToAcrossProjects($user, $projects)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->where(fn ($query) => $query
                ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('due_date', [$from->toDateString(), $to->toDateString()]))
            ->with(['project', 'tracker'])
            ->get()
            ->sortBy(fn (Issue $issue) => min(array_filter([
                $this->inWindow($issue->start_date, $from, $to) ? $issue->start_date->toDateString() : null,
                $this->inWindow($issue->due_date, $from, $to) ? $issue->due_date->toDateString() : null,
            ])))
            ->take(self::MAX_ROWS)
            ->values()
            ->map(function (Issue $issue) use ($from, $to) {
                $startsNow = $this->inWindow($issue->start_date, $from, $to);
                $dueNow = $this->inWindow($issue->due_date, $from, $to);

                return new DashboardBlockRow(
                    title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                    url: route('issues.show', [$issue->project, $issue]),
                    meta: implode(' / ', array_filter([
                        $startsNow ? '開始 '.$issue->start_date->toDateString() : null,
                        $dueNow ? '期日 '.$issue->due_date->toDateString() : null,
                    ])),
                );
            });
    }

    private function inWindow(?\Carbon\CarbonInterface $date, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): bool
    {
        return $date !== null && $date->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }
}
