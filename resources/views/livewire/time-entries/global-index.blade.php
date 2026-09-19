<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Redmine's top-level TimelogController#index (no project_id) — every time
 * entry across every project the current user can view_time_entries in.
 * Rows can be edited and deleted where the viewer may (each entry is checked
 * against its own project), and the filtered list exports as CSV. Only a
 * single sort level rather than the project-scoped page's grouping-plus-sort
 * combo, as in issues.global-index. The pivoted multi-axis report is
 * time-entries.report on /time_entries/report.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

    /**
     * @var array<string, string>
     */
    public const array DISPLAY_COLUMNS = [
        'project_id' => 'プロジェクト',
        'spent_on' => '日付',
        'user_id' => '担当者',
        'activity_id' => '作業分類',
        'issue_id' => '課題',
        'comments' => 'コメント',
        'hours' => '時間',
    ];

    #[Url]
    public ?string $sortKey = 'spent_on';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public ?string $groupBy = null;

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['project_id', 'spent_on', 'user_id', 'activity_id', 'issue_id', 'hours'];

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->with('users')
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('viewAny', [TimeEntry::class, $project]))
            ->values();
    }

    #[Computed]
    public function engine(): QueryFilterEngine
    {
        return new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProjects($this->visibleProjects));
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filteredTimeEntriesQuery(): Builder
    {
        $query = TimeEntry::query()
            ->visibleToAcrossProjects(auth()->user(), $this->visibleProjects)
            ->with(['project', 'user', 'activity', 'issue']);

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        if ($this->sortKey !== null) {
            $query = $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
        } else {
            $query->orderByDesc('spent_on');
        }

        return $query;
    }

    #[Computed]
    public function timeEntries(): EloquentCollection
    {
        return $this->filteredTimeEntriesQuery()->get();
    }

    /**
     * @return Collection<string, EloquentCollection<int, TimeEntry>>
     */
    #[Computed]
    public function groupedTimeEntries(): Collection
    {
        if ($this->groupBy === null) {
            return collect(['' => $this->timeEntries]);
        }

        return $this->timeEntries->groupBy(fn (TimeEntry $entry) => $this->columnValue($entry, $this->groupBy));
    }

    public function applyFilters(): void
    {
        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    public function sortBy(string $key): void
    {
        if ($this->sortKey === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Deletes one entry the viewer may delete, whichever project it is in.
     */
    public function deleteEntry(int $timeEntryId): void
    {
        $entry = TimeEntry::query()
            ->visibleToAcrossProjects(auth()->user(), $this->visibleProjects)
            ->with('project')
            ->find($timeEntryId);

        abort_if($entry === null, 404);
        $this->authorize('delete', $entry);

        app(TimeEntryService::class)->delete($entry);

        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    #[Url]
    public string $csvEncoding = 'UTF-8';

    #[Url]
    public string $csvSeparator = ',';

    /**
     * The filtered list, in the chosen columns, as CSV (UTF-8 with a byte-order
     * mark, or Shift_JIS).
     */
    public function exportCsv(): StreamedResponse
    {
        $encoding = in_array($this->csvEncoding, ['UTF-8', 'SJIS-win'], true) ? $this->csvEncoding : 'UTF-8';
        $separator = in_array($this->csvSeparator, [',', ';', "\t"], true) ? $this->csvSeparator : ',';
        $columns = $this->columns;
        $entries = $this->timeEntries;

        return response()->streamDownload(function () use ($columns, $entries, $encoding, $separator): void {
            $handle = fopen('php://output', 'w');

            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $write = function (array $row) use ($handle, $encoding, $separator): void {
                fputcsv($handle, array_map(fn ($value) => $encoding === 'UTF-8' ? (string) $value : mb_convert_encoding((string) $value, $encoding, 'UTF-8'), $row), $separator);
            };

            $write(array_map(fn (string $key) => self::DISPLAY_COLUMNS[$key] ?? $key, $columns));

            foreach ($entries as $entry) {
                $write(array_map(fn (string $key) => $this->columnValue($entry, $key), $columns));
            }

            fclose($handle);
        }, 'timelog.csv');
    }

    public function columnValue(TimeEntry $entry, string $key): string
    {
        return match ($key) {
            'project_id' => $entry->project->name,
            'user_id' => $entry->user->name,
            'activity_id' => $entry->activity->name,
            'spent_on' => $entry->spent_on->toDateString(),
            'hours' => (string) $entry->hours,
            'issue_id' => $entry->issue ? "#{$entry->issue->id} {$entry->issue->subject}" : '',
            'comments' => (string) $entry->comments,
            default => '',
        };
    }

    #[Computed]
    public function totalHours(): string
    {
        return $this->formatHours($this->timeEntries);
    }

    public function groupTotalHours(EloquentCollection $entries): string
    {
        return $this->formatHours($entries);
    }

    /**
     * @param  EloquentCollection<int, TimeEntry>  $entries
     */
    private function formatHours(EloquentCollection $entries): string
    {
        return \App\Support\Format\Hours::format((float) $entries->sum('hours'));
    }

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_admin;
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleGlobally(QueryType::TimeEntry, auth()->user());
    }

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], null);

        $query = SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::TimeEntry->value,
            'user_id' => auth()->id(),
            'project_id' => null,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => $this->columns,
            'sort_criteria' => $this->sortKey ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => $this->groupBy,
        ]);

        if ($visibility === QueryVisibility::Roles->value) {
            $query->roles()->sync($data['newQueryRoleIds']);
        }

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', 'クエリを保存しました。');
    }

    public function loadQuery(int $queryId): void
    {
        $query = SavedQuery::query()->whereNull('project_id')->findOrFail($queryId);

        abort_unless($query->visibleTo(auth()->user()), 403);

        $this->activeFilterKeys = array_keys($query->filters);
        $this->filterOperators = [];
        $this->filterValues = [];

        foreach ($query->filters as $key => $filter) {
            $this->filterOperators[$key] = $filter['operator'];
            $this->filterValues[$key] = $filter['values'] ?? [];
        }

        $this->columns = $query->column_names !== [] ? $query->column_names : array_keys(self::DISPLAY_COLUMNS);
        $this->groupBy = $query->group_by;

        if ($query->sort_criteria !== [] && $query->sort_criteria !== null) {
            [$this->sortKey, $this->sortDirection] = $query->sort_criteria[0];
        }

        unset($this->timeEntries, $this->groupedTimeEntries);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">工数(全プロジェクト)</h1>
            <p class="mt-1 text-sm text-gray-500">合計: {{ $this->totalHours }} 時間</p>
        </div>
    </div>

    {{-- Saved queries --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-500">保存済みクエリ:</span>
        @forelse ($this->savedQueries as $savedQuery)
            <button wire:key="saved-query-{{ $savedQuery->id }}" wire:click="loadQuery({{ $savedQuery->id }})" class="rounded-full border border-gray-300 px-3 py-1 text-gray-700 hover:bg-gray-50">
                {{ $savedQuery->name }}
            </button>
        @empty
            <span class="text-gray-400">なし</span>
        @endforelse
    </div>

    {{-- Filter builder --}}
    <div class="mb-4 rounded-md border border-gray-200 bg-white p-4">
        <x-query-filter-builder :engine="$this->engine" :active-filter-keys="$activeFilterKeys" :filter-operators="$filterOperators" />

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button wire:click="applyFilters" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                絞り込み適用
            </button>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                グループ化:
                <select wire:model.live="groupBy" class="rounded-md border-gray-300 text-sm">
                    <option value="">なし</option>
                    <option value="project_id">プロジェクト</option>
                    <option value="user_id">担当者</option>
                    <option value="activity_id">作業分類</option>
                    <option value="spent_on">日付</option>
                </select>
            </label>

            <div class="flex items-center gap-2 text-sm text-gray-700">
                表示列:
                @foreach (self::DISPLAY_COLUMNS as $key => $label)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="columns" value="{{ $key }}" class="rounded border-gray-300">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            <button wire:click="$toggle('showSaveForm')" class="text-sm text-indigo-600 hover:underline">クエリを保存</button>

            <select wire:model="csvEncoding" title="文字コード" class="rounded-md border-gray-300 text-xs">
                <option value="UTF-8">UTF-8</option>
                <option value="SJIS-win">Shift_JIS</option>
            </select>
            <select wire:model="csvSeparator" title="区切り文字" class="rounded-md border-gray-300 text-xs">
                <option value=",">カンマ</option>
                <option value=";">セミコロン</option>
                <option value="{{ "\t" }}">タブ</option>
            </select>
            <button wire:click="exportCsv" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">CSVエクスポート</button>
            <a href="{{ route('time-entries.global-report') }}" class="text-sm text-indigo-600 hover:underline">レポート</a>
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles" />
        @endif
    </div>

    @foreach ($this->groupedTimeEntries as $groupLabel => $groupEntries)
        @php $groupKey = $groupLabel !== '' ? $groupLabel : '__ungrouped__'; @endphp
        @if ($groupBy !== null)
            <h2 wire:key="group-heading-{{ $groupKey }}" class="mb-2 mt-4 text-sm font-semibold text-gray-900">
                {{ $groupLabel ?: '(未設定)' }} ({{ $groupEntries->count() }}件 / {{ $this->groupTotalHours($groupEntries) }} 時間)
            </h2>
        @endif

        <div wire:key="group-table-{{ $groupKey }}" class="overflow-x-auto rounded-md border border-gray-200 bg-white mb-4">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        @foreach ($columns as $columnKey)
                            <th wire:key="column-heading-{{ $columnKey }}" class="px-4 py-2">
                                <button wire:click="sortBy('{{ $columnKey }}')" class="flex items-center gap-1 hover:text-gray-900">
                                    {{ self::DISPLAY_COLUMNS[$columnKey] ?? $columnKey }}
                                    @if ($sortKey === $columnKey)
                                        <span>{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groupEntries as $entry)
                        <tr wire:key="time-entry-{{ $entry->id }}">
                            @foreach ($columns as $columnKey)
                                <td wire:key="time-entry-{{ $entry->id }}-column-{{ $columnKey }}" class="px-4 py-2">
                                    @if ($columnKey === 'issue_id')
                                        @if ($entry->issue)
                                            <a href="{{ route('issues.show', [$entry->project, $entry->issue]) }}" class="text-indigo-600 hover:underline">
                                                #{{ $entry->issue->id }} {{ $entry->issue->subject }}
                                            </a>
                                        @else
                                            -
                                        @endif
                                    @else
                                        {{ $this->columnValue($entry, $columnKey) }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-4 py-2 whitespace-nowrap">
                                @can('update', $entry)
                                    <a href="{{ route('time-entries.edit', [$entry->project, $entry]) }}" class="text-indigo-600 hover:underline">編集</a>
                                @endcan
                                @can('delete', $entry)
                                    <button wire:click="deleteEntry({{ $entry->id }})" wire:confirm="この工数記録を削除しますか?" class="ml-2 text-red-600 hover:underline">削除</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="px-4 py-6 text-center text-gray-500">工数記録がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
