<?php

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Redmine's top-level IssuesController#calendar (no project_id) — the same
 * month grid as calendar.index, but across every project the viewer can
 * view_calendar in. Reuses Issue::scopeVisibleToAcrossProjects() exactly
 * as issues.global-index does; weeks()'s grid-building logic is otherwise
 * unchanged. The one new piece is a per-entry project label, since a
 * day cell here can mix issues from several projects.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public int $year = 0;

    #[Url]
    public int $month = 0;

    public function mount(): void
    {
        $this->year = $this->year ?: now()->year;
        $this->month = $this->month ?: now()->month;
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('viewCalendar', $project))
            ->values();
    }

    /**
     * @return array<int, array<int, array{date: Carbon, entries: Collection<int, array{issue: Issue, marker: string}>, isCurrentMonth: bool}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $firstOfMonth = Carbon::create($this->year, $this->month, 1);
        $gridStart = $firstOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $entriesByDate = $this->issueEntriesBetween($gridStart, $gridEnd);

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor->copy(),
                    'entries' => $entriesByDate->get($cursor->toDateString(), collect()),
                    'isCurrentMonth' => $cursor->month === $this->month,
                ];
                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * @return Collection<string, Collection<int, array{issue: Issue, marker: string}>>
     */
    private function issueEntriesBetween(Carbon $from, Carbon $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $issues = Issue::query()
            ->visibleToAcrossProjects(auth()->user(), $this->visibleProjects)
            ->where(fn ($query) => $query
                ->whereBetween('start_date', $range)
                ->orWhereBetween('due_date', $range))
            ->with(['project', 'tracker', 'status'])
            ->get();

        $inRange = fn (?string $date) => $date !== null && $date >= $range[0] && $date <= $range[1];
        $entries = collect();

        foreach ($issues as $issue) {
            $start = $issue->start_date?->toDateString();
            $due = $issue->due_date?->toDateString();

            if ($inRange($start)) {
                $entries->push(['date' => $start, 'issue' => $issue, 'marker' => $start === $due ? 'both' : 'start']);
            }

            if ($inRange($due) && $due !== $start) {
                $entries->push(['date' => $due, 'issue' => $issue, 'marker' => 'due']);
            }
        }

        return $entries->groupBy('date');
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonthNoOverflow();
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->weeks);
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonthNoOverflow();
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->weeks);
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900">カレンダー(全プロジェクト)</h1>
        <div class="flex items-center gap-3">
            <button wire:click="previousMonth" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">‹</button>
            <span class="text-sm font-medium text-gray-900">{{ $year }}年{{ $month }}月</span>
            <button wire:click="nextMonth" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">›</button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full table-fixed divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    @foreach (['日', '月', '火', '水', '木', '金', '土'] as $label)
                        <th class="px-2 py-2 text-center">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($this->weeks as $week)
                    <tr wire:key="week-{{ $week[0]['date']->toDateString() }}" class="align-top">
                        @foreach ($week as $day)
                            <td wire:key="day-{{ $day['date']->toDateString() }}"
                                class="h-28 px-2 py-1 {{ $day['isCurrentMonth'] ? 'bg-white' : 'bg-gray-50 text-gray-400' }}">
                                <div class="text-xs {{ $day['date']->isToday() ? 'font-bold text-indigo-600' : 'text-gray-500' }}">
                                    {{ $day['date']->day }}
                                </div>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($day['entries'] as $entry)
                                        @php $issue = $entry['issue']; @endphp
                                        <li class="truncate" wire:key="cal-{{ $day['date']->toDateString() }}-{{ $issue->id }}-{{ $entry['marker'] }}">
                                            @php [$markerLabel, $markerSymbol] = match ($entry['marker']) { 'start' => ['開始日', '▶'], 'due' => ['期日', '◀'], default => ['開始日=期日', '◆'] }; @endphp
                                            <span class="text-xs text-gray-400" title="{{ $markerLabel }}">{{ $markerSymbol }}</span>
                                            <a href="{{ route('issues.show', [$issue->project, $issue]) }}"
                                                class="text-xs text-indigo-600 hover:underline"
                                                title="{{ $issue->project->name }} — {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}">
                                                {{ $issue->project->identifier }} #{{ $issue->id }} {{ $issue->subject }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
