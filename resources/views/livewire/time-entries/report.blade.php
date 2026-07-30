<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\TimeEntryVisibility;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use App\Support\TimeReport\TimeReportBuilder;
use App\Support\TimeReport\TimeReportCriterion;
use App\Support\TimeReport\TimeReportPeriod;
use App\Support\TimeReport\TimeReportTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Multi-dimensional time report — a pivot of up to 3 row criteria x one
 * period column axis, mirroring Redmine's /projects/:id/time_entries/report
 * (Redmine::Helpers::TimeReport). See TimeReportBuilder for the query/pivot
 * logic this component only wires filters and criteria/period selection into.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

    public Project $project;

    /** @var array<int, string> */
    #[Url]
    public array $criteria = ['user'];

    #[Url]
    public string $period = 'month';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [TimeEntry::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProject($this->project));
    }

    /**
     * @return array<int, TimeReportCriterion>
     */
    #[Computed]
    public function selectedCriteria(): array
    {
        return collect($this->criteria)
            ->map(fn (string $key) => TimeReportCriterion::tryFrom($key))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    #[Computed]
    public function selectedPeriod(): TimeReportPeriod
    {
        return TimeReportPeriod::tryFrom($this->period) ?? TimeReportPeriod::Month;
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filteredTimeEntriesQuery(): Builder
    {
        $query = TimeEntry::query()->where('time_entries.project_id', $this->project->id);

        if (app(AuthorizationService::class)->timeEntryVisibilityFor(auth()->user(), $this->project) === TimeEntryVisibility::Own) {
            $query->where('time_entries.user_id', auth()->id());
        }

        return $this->engine->applyFilters($query, $this->builtFilters());
    }

    #[Computed]
    public function report(): TimeReportTable
    {
        return app(TimeReportBuilder::class)->build($this->filteredTimeEntriesQuery(), $this->selectedCriteria, $this->selectedPeriod);
    }

    public function toggleCriterion(string $key): void
    {
        if (in_array($key, $this->criteria, true)) {
            $this->criteria = array_values(array_diff($this->criteria, [$key]));
        } elseif (count($this->criteria) < 3) {
            $this->criteria[] = $key;
        }

        unset($this->report);
    }

    public function applyFilters(): void
    {
        unset($this->report);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 工数レポート</h1>
        <p class="mt-1 text-sm text-gray-500">合計: {{ number_format($this->report->grandTotal, 2) }} 時間</p>
    </div>

    {{-- Filter builder --}}
    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-6">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                絞り込み適用
            </button>

            <div class="flex items-center gap-2 text-sm text-gray-700">
                行の軸(最大3つ):
                @foreach (\App\Support\TimeReport\TimeReportCriterion::cases() as $criterion)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:click="toggleCriterion('{{ $criterion->value }}')"
                            @checked(in_array($criterion->value, $criteria, true))
                            @disabled(count($criteria) >= 3 && ! in_array($criterion->value, $criteria, true))
                            class="rounded border-gray-300">
                        {{ $criterion->label() }}
                    </label>
                @endforeach
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                列の軸:
                <select wire:model.live="period" class="rounded-md border-gray-300 text-sm">
                    @foreach (\App\Support\TimeReport\TimeReportPeriod::cases() as $periodOption)
                        <option value="{{ $periodOption->value }}">{{ $periodOption->label() }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    @if ($this->report->isEmpty())
        <p class="text-sm text-gray-500">行の軸を1つ以上選択してください。該当する工数記録がない場合も表は空になります。</p>
    @else
        <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach ($this->selectedCriteria as $criterion)
                            <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $criterion->label() }}</th>
                        @endforeach
                        @foreach ($this->report->periods as $columnPeriod)
                            <th class="px-3 py-2 text-right font-medium text-gray-500">{{ $columnPeriod['label'] }}</th>
                        @endforeach
                        <th class="px-3 py-2 text-right font-medium text-gray-500">合計</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->report->rows as $row)
                        <tr wire:key="report-row-{{ $loop->index }}">
                            @foreach ($row['labels'] as $label)
                                <td class="px-3 py-2 text-gray-700">{{ $label }}</td>
                            @endforeach
                            @foreach ($this->report->periods as $columnPeriod)
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">
                                    {{ isset($row['cells'][$columnPeriod['key']]) ? number_format($row['cells'][$columnPeriod['key']], 2) : '' }}
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-right tabular-nums font-medium text-gray-900">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-500" colspan="{{ count($this->selectedCriteria) }}">合計</th>
                        @foreach ($this->report->periods as $columnPeriod)
                            <th class="px-3 py-2 text-right font-medium text-gray-900">{{ number_format($this->report->columnTotals[$columnPeriod['key']] ?? 0, 2) }}</th>
                        @endforeach
                        <th class="px-3 py-2 text-right font-medium text-gray-900">{{ number_format($this->report->grandTotal, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
