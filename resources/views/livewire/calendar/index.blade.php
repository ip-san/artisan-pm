<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Version;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

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

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($this->project));
    }

    /**
     * Matches Redmine's Setting.start_of_week: 0/1/6 (Sun/Mon/Sat, Carbon's
     * own day-of-week constants), defaulting to Sunday — this app has no
     * locale system to fall back to Redmine's language-based default, so
     * the prior hardcoded Sunday behavior is kept as the default here.
     */
    #[Computed]
    public function startOfWeek(): int
    {
        return Setting::get('start_of_week', Carbon::SUNDAY);
    }

    /**
     * The header row's day labels, rotated to start on the same day as
     * the grid itself.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function weekdayLabels(): array
    {
        $labels = ['日', '月', '火', '水', '木', '金', '土'];

        return [...array_slice($labels, $this->startOfWeek), ...array_slice($labels, 0, $this->startOfWeek)];
    }

    /**
     * Issues appear on their start date (▶) and their due date (◀) —
     * matching Redmine's calendar helper, which marks an issue on those
     * two days rather than spanning every day in between (the plan's
     * "month grid, not a mini-Gantt per cell" scope). An issue starting
     * and due the same day collapses to a single ◆ entry.
     *
     * @return array<int, array<int, array{date: Carbon, entries: Collection<int, array{issue: Issue, marker: string}>, isCurrentMonth: bool}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $firstOfMonth = Carbon::create($this->year, $this->month, 1);
        $gridStart = $firstOfMonth->copy()->startOfWeek($this->startOfWeek);
        $gridEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(($this->startOfWeek + 6) % 7);

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
     * Issues on their start/due dates plus this project's versions on
     * their due date (Redmine's calendars_controller also merges
     * `@query.versions` whose effective_date falls in the grid).
     *
     * @return Collection<string, Collection<int, array{issue: Issue|null, version: Version|null, marker: string}>>
     */
    private function issueEntriesBetween(Carbon $from, Carbon $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $query = Issue::query()
            ->where('project_id', $this->project->id)
            ->where(fn ($query) => $query
                ->whereBetween('start_date', $range)
                ->orWhereBetween('due_date', $range))
            ->with(['tracker', 'status']);

        $issues = $this->engine->applyFilters($query, $this->builtFilters())->get();

        $inRange = fn (?string $date) => $date !== null && $date >= $range[0] && $date <= $range[1];
        $entries = collect();

        foreach ($issues as $issue) {
            $start = $issue->start_date?->toDateString();
            $due = $issue->due_date?->toDateString();

            if ($inRange($start)) {
                $entries->push(['date' => $start, 'issue' => $issue, 'version' => null, 'marker' => $start === $due ? 'both' : 'start']);
            }

            if ($inRange($due) && $due !== $start) {
                $entries->push(['date' => $due, 'issue' => $issue, 'version' => null, 'marker' => 'due']);
            }
        }

        foreach (Version::query()->where('project_id', $this->project->id)->whereBetween('due_date', $range)->orderBy('name')->get() as $version) {
            $entries->push(['date' => $version->due_date->toDateString(), 'issue' => null, 'version' => $version, 'marker' => 'version']);
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

    public function applyFilters(): void
    {
        unset($this->weeks);
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — カレンダー</h1>
        <div class="flex items-center gap-3">
            <button wire:click="previousMonth" class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50">‹</button>
            <span class="text-sm font-medium text-neutral-900">{{ $year }}年{{ $month }}月</span>
            <button wire:click="nextMonth" class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50">›</button>
        </div>
    </div>

    <div class="mb-4 rounded-md border border-neutral-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3">
            <button wire:click="applyFilters" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                絞り込み適用
            </button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
        <table class="min-w-full table-fixed divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500">
                <tr>
                    @foreach ($this->weekdayLabels as $label)
                        <th class="px-2 py-2 text-center">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @foreach ($this->weeks as $week)
                    <tr wire:key="week-{{ $week[0]['date']->toDateString() }}" class="align-top">
                        @foreach ($week as $day)
                            <td wire:key="day-{{ $day['date']->toDateString() }}"
                                class="h-28 px-2 py-1 {{ $day['isCurrentMonth'] ? 'bg-white' : 'bg-neutral-50 text-neutral-400' }}">
                                <div class="text-xs {{ $day['date']->isToday() ? 'font-bold text-brand-bold' : 'text-neutral-500' }}">
                                    {{ $day['date']->day }}
                                </div>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($day['entries'] as $entry)
                                        @if ($entry['marker'] === 'version')
                                            <li class="truncate" wire:key="cal-{{ $day['date']->toDateString() }}-version-{{ $entry['version']->id }}">
                                                <span class="text-xs text-neutral-400" title="バージョンの期日">📦</span>
                                                <a href="{{ route('versions.roadmap', $project) }}#roadmap-version-{{ $entry['version']->id }}"
                                                    class="text-xs text-brand-bold hover:underline"
                                                    title="バージョン: {{ $entry['version']->name }}">
                                                    {{ $entry['version']->name }}
                                                </a>
                                            </li>
                                            @continue
                                        @endif
                                        @php $issue = $entry['issue']; @endphp
                                        <li class="truncate" wire:key="cal-{{ $day['date']->toDateString() }}-{{ $issue->id }}-{{ $entry['marker'] }}">
                                            @php [$markerLabel, $markerSymbol] = match ($entry['marker']) { 'start' => ['開始日', '▶'], 'due' => ['期日', '◀'], default => ['開始日=期日', '◆'] }; @endphp
                                            <span class="text-xs text-neutral-400" title="{{ $markerLabel }}">{{ $markerSymbol }}</span>
                                            <a href="{{ route('issues.show', [$project, $issue]) }}"
                                                class="text-xs text-brand-bold hover:underline"
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
