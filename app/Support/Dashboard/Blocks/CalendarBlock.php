<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use App\Support\Format\DateTimes;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Redmine's calendar My Page block: Redmine::Helpers::Calendar.new(today,
 * lang, :week) — the calendar week (Setting.start_of_week) containing
 * today, across the user's projects. weekDays() builds that grid; rows()
 * (the plain DashboardBlock interface every other block implements) stays
 * as a flat fallback list for callers that can't render a grid.
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
        return __('今週のカレンダー');
    }

    /**
     * @return array<int, array{date: Carbon, entries: Collection<int, array{issue: Issue, marker: string}>}>
     */
    public function weekDays(User $user): array
    {
        $startOfWeek = Setting::get('start_of_week', Carbon::SUNDAY);
        $today = Carbon::parse(DateTimes::today($user)->toDateString());
        $from = $today->copy()->startOfWeek($startOfWeek);
        $to = $from->copy()->addDays(6);

        $projects = $user->projects()->get()->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))->values();
        $range = [$from->toDateString(), $to->toDateString()];

        $issues = Issue::query()
            ->visibleToAcrossProjects($user, $projects)
            ->where(fn ($query) => $query->whereBetween('start_date', $range)->orWhereBetween('due_date', $range))
            ->with(['project', 'tracker'])
            ->get();

        $entriesByDate = collect();
        $inRange = fn (?string $date) => $date !== null && $date >= $range[0] && $date <= $range[1];

        foreach ($issues as $issue) {
            $start = $issue->start_date?->toDateString();
            $due = $issue->due_date?->toDateString();

            if ($inRange($start)) {
                $entriesByDate->push(['date' => $start, 'issue' => $issue, 'marker' => $start === $due ? 'both' : 'start']);
            }

            if ($inRange($due) && $due !== $start) {
                $entriesByDate->push(['date' => $due, 'issue' => $issue, 'marker' => 'due']);
            }
        }

        $entriesByDate = $entriesByDate->groupBy('date');

        $days = [];
        $cursor = $from->copy();

        for ($i = 0; $i < 7; $i++) {
            $days[] = ['date' => $cursor->copy(), 'entries' => $entriesByDate->get($cursor->toDateString(), collect())];
            $cursor->addDay();
        }

        return $days;
    }

    public function rows(User $user): Collection
    {
        $projects = $user->projects()->get()->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))->values();
        // The user's today, as a date (start/due dates are days, not moments).
        $from = Carbon::parse(DateTimes::today($user)->toDateString());
        $to = $from->copy()->addDays(self::DAYS_AHEAD)->endOfDay();

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
                        $startsNow ? __('開始 :date', ['date' => DateTimes::date($issue->start_date)]) : null,
                        $dueNow ? __('期日 :date', ['date' => DateTimes::date($issue->due_date)]) : null,
                    ])),
                );
            });
    }

    private function inWindow(?CarbonInterface $date, CarbonInterface $from, CarbonInterface $to): bool
    {
        return $date !== null && $date->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }
}
