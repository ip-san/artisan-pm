<?php

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public int $year = 0;

    #[Url]
    public int $month = 0;

    public function mount(Project $project): void
    {
        $this->authorize('viewCalendar', $project);

        $this->project = $project;
        $this->year = $this->year ?: now()->year;
        $this->month = $this->month ?: now()->month;
    }

    /**
     * Issues are placed on their due date only, not spanning every day
     * between start and due date — the plan scopes the calendar to "a
     * month grid of due dates", not a mini-Gantt per cell.
     *
     * @return array<int, array<int, array{date: Carbon, issues: Collection<int, Issue>, isCurrentMonth: bool}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $firstOfMonth = Carbon::create($this->year, $this->month, 1);
        $gridStart = $firstOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $issuesByDate = $this->issuesDueBetween($gridStart, $gridEnd);

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor->copy(),
                    'issues' => $issuesByDate->get($cursor->toDateString(), collect()),
                    'isCurrentMonth' => $cursor->month === $this->month,
                ];
                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * @return Collection<string, Collection<int, Issue>>
     */
    private function issuesDueBetween(Carbon $from, Carbon $to): Collection
    {
        return Issue::query()
            ->where('project_id', $this->project->id)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with(['tracker', 'status'])
            ->get()
            ->groupBy(fn (Issue $issue) => $issue->due_date->toDateString());
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
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — カレンダー</h1>
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
                                    @foreach ($day['issues'] as $issue)
                                        <li class="truncate">
                                            <a href="{{ route('issues.show', [$project, $issue]) }}"
                                                class="text-xs text-indigo-600 hover:underline"
                                                title="{{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}">
                                                #{{ $issue->id }} {{ $issue->subject }}
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
