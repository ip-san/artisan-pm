<?php

use App\Concerns\InteractsWithQueryFilters;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\TimeEntryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Authorization\AuthorizationService;
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

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithQueryFilters;

    /**
     * Columns selectable for display/CSV export — mirrors issues.index's
     * own DISPLAY_COLUMNS. 'issue_id' and 'comments' aren't registered
     * filter fields (see TimeEntryFilterFieldRegistry), so sorting by
     * them is a harmless no-op rather than a real sort, same as any
     * unregistered column on the issues list.
     *
     * @var array<string, string>
     */
    public const DISPLAY_COLUMNS = [
        'spent_on' => '日付',
        'user_id' => '担当者',
        'activity_id' => '作業分類',
        'issue_id' => '課題',
        'comments' => 'コメント',
        'hours' => '時間',
    ];

    public Project $project;

    #[Url]
    public ?string $sortKey = 'spent_on';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public ?string $groupBy = null;

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['spent_on', 'user_id', 'activity_id', 'issue_id', 'comments', 'hours'];

    public string $csvEncoding = 'UTF-8';

    public string $csvSeparator = ',';

    /** @var array<int, int> */
    public array $selected = [];

    public ?int $bulkActivityId = null;

    public string $bulkSpentOn = '';

    public string $bulkComments = '';

    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

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
     * @return Builder<TimeEntry>
     */
    private function filteredTimeEntriesQuery(): Builder
    {
        $query = TimeEntry::query()
            ->where('project_id', $this->project->id)
            ->with(['user', 'activity', 'issue']);

        if (app(AuthorizationService::class)->timeEntryVisibilityFor(auth()->user(), $this->project) === TimeEntryVisibility::Own) {
            $query->where('user_id', auth()->id());
        }

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

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'manage_public_queries', $this->project);
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $this->project);

        $query = SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::TimeEntry->value,
            'user_id' => auth()->id(),
            'project_id' => $this->project->id,
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
        $query = SavedQuery::query()
            ->where(fn ($q) => $q->where('project_id', $this->project->id)->orWhereNull('project_id'))
            ->findOrFail($queryId);

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

    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::visibleIn($this->project, QueryType::TimeEntry, auth()->user());
    }

    public function columnValue(TimeEntry $entry, string $key): string
    {
        return match ($key) {
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
        return Number::format((float) $entries->sum('hours'), precision: 2);
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'edit_time_entries', $this->project);
    }

    public function deleteEntry(int $timeEntryId): void
    {
        $entry = TimeEntry::query()->where('project_id', $this->project->id)->findOrFail($timeEntryId);

        $this->authorize('delete', $entry);

        app(TimeEntryService::class)->delete($entry);

        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    /**
     * This project's effective TimeEntryActivity set — mirrors
     * time-entries/form.blade.php's own `activities()` computed.
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->project->activities(includeInactive: true);
    }

    /**
     * @return EloquentCollection<int, TimeEntry>
     */
    #[Computed]
    public function selectedTimeEntries(): EloquentCollection
    {
        if ($this->selected === []) {
            return new EloquentCollection;
        }

        return TimeEntry::query()
            ->whereIn('id', $this->selected)
            ->where('project_id', $this->project->id)
            ->get();
    }

    public function applyBulkEdit(): void
    {
        $entries = $this->selectedTimeEntries;

        abort_if($entries->isEmpty(), 404);

        foreach ($entries as $entry) {
            $this->authorize('update', $entry);
        }

        $data = $this->validate([
            'bulkActivityId' => ['nullable', Rule::in($this->activities->pluck('id')->all())],
            'bulkSpentOn' => [$this->bulkSpentOn === '' ? 'nullable' : 'date'],
            'bulkComments' => ['nullable', 'string'],
        ]);

        $changes = array_filter([
            'activity_id' => $data['bulkActivityId'],
            'spent_on' => $data['bulkSpentOn'] !== '' ? $data['bulkSpentOn'] : null,
            'comments' => $data['bulkComments'] !== '' ? $data['bulkComments'] : null,
        ], fn ($value) => $value !== null);

        $timeEntryService = app(TimeEntryService::class);

        foreach ($entries as $entry) {
            $timeEntryService->update($entry, $changes);
        }

        $count = $entries->count();

        $this->reset(['selected', 'bulkActivityId', 'bulkSpentOn', 'bulkComments']);
        unset($this->timeEntries, $this->groupedTimeEntries, $this->selectedTimeEntries);

        session()->flash('status', "{$count}件の工数記録を更新しました。");
    }

    public function applyBulkDelete(): void
    {
        $entries = $this->selectedTimeEntries;

        abort_if($entries->isEmpty(), 404);

        foreach ($entries as $entry) {
            $this->authorize('delete', $entry);
        }

        $count = $entries->count();
        $timeEntryService = app(TimeEntryService::class);

        foreach ($entries as $entry) {
            $timeEntryService->delete($entry);
        }

        $this->reset('selected');
        unset($this->timeEntries, $this->groupedTimeEntries, $this->selectedTimeEntries);

        session()->flash('status', "{$count}件の工数記録を削除しました。");
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', [TimeEntry::class, $this->project]);

        $query = $this->filteredTimeEntriesQuery();
        $columns = $this->columns;
        // Re-validated against the allowlist here rather than trusted from
        // the live property, since these drive raw file-writing behavior.
        $encoding = in_array($this->csvEncoding, ['UTF-8', 'SJIS-win'], true) ? $this->csvEncoding : 'UTF-8';
        $separator = in_array($this->csvSeparator, [',', ';', "\t"], true) ? $this->csvSeparator : ',';

        return response()->streamDownload(function () use ($query, $columns, $encoding, $separator) {
            $handle = fopen('php://output', 'w');

            // A UTF-8 BOM lets Excel auto-detect the encoding instead of
            // mis-rendering non-ASCII text as mojibake — matches Redmine's
            // Redmine::Export::CSV, which does the same for UTF-8 exports.
            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $writeRow = function (array $row) use ($handle, $separator, $encoding): void {
                if ($encoding !== 'UTF-8') {
                    $row = array_map(fn (string $value) => mb_convert_encoding($value, $encoding, 'UTF-8'), $row);
                }

                fputcsv($handle, $row, $separator);
            };

            $writeRow(array_map(fn (string $key) => self::DISPLAY_COLUMNS[$key] ?? $key, $columns));

            $query->chunk(200, function ($chunk) use ($writeRow, $columns) {
                foreach ($chunk as $entry) {
                    $writeRow(array_map(fn (string $key) => $this->columnValue($entry, $key), $columns));
                }
            });

            fclose($handle);
        }, "{$this->project->identifier}-time_entries.csv");
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 工数</h1>
            <p class="mt-1 text-sm text-gray-500">合計: {{ $this->totalHours }} 時間</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model="csvEncoding" title="文字コード" class="rounded-md border-gray-300 text-xs">
                <option value="UTF-8">UTF-8</option>
                <option value="SJIS-win">Shift_JIS</option>
            </select>
            <select wire:model="csvSeparator" title="区切り文字" class="rounded-md border-gray-300 text-xs">
                <option value=",">カンマ</option>
                <option value=";">セミコロン</option>
                <option value="{{ "\t" }}">タブ</option>
            </select>
            <button wire:click="exportCsv" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                CSVエクスポート
            </button>
            @can('create', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.import', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    CSVインポート
                </a>
                <a href="{{ route('time-entries.create', $project) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    工数を記録
                </a>
            @endcan
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
        </div>

        @if ($showSaveForm)
            <x-saved-query-save-form
                :can-manage-public-queries="$this->canManagePublicQueries"
                :visibility="$newQueryVisibility"
                :roles="$this->availableRoles" />
        @endif
    </div>

    @if ($this->canManage && count($selected) > 0)
        <form wire:submit="applyBulkEdit" class="mb-4 space-y-3 rounded-md border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm font-medium text-gray-900">{{ count($selected) }}件を選択中 — 変更する項目だけ設定してください</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700">作業分類</label>
                    <select wire:model="bulkActivityId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->activities as $activity)
                            <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">日付</label>
                    <input type="date" wire:model="bulkSpentOn" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700">コメント(変更する場合のみ入力)</label>
                <textarea wire:model="bulkComments" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm"></textarea>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    一括更新
                </button>
                <button type="button" wire:click="applyBulkDelete" wire:confirm="選択した{{ count($selected) }}件の工数記録を削除します。この操作は取り消せません。よろしいですか?"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    選択した{{ count($selected) }}件を削除
                </button>
                <button type="button" wire:click="$set('selected', [])" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-white">
                    選択解除
                </button>
            </div>
        </form>
    @endif

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
                        @if ($this->canManage)
                            <th class="px-4 py-2"></th>
                        @endif
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
                        @if ($this->canManage)
                            <th class="px-4 py-2"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groupEntries as $entry)
                        <tr wire:key="time-entry-{{ $entry->id }}">
                            @if ($this->canManage)
                                <td class="px-4 py-2">
                                    <input type="checkbox" wire:model="selected" value="{{ $entry->id }}" class="rounded border-gray-300">
                                </td>
                            @endif
                            @foreach ($columns as $columnKey)
                                <td wire:key="time-entry-{{ $entry->id }}-column-{{ $columnKey }}" class="px-4 py-2">
                                    @if ($columnKey === 'issue_id')
                                        @if ($entry->issue)
                                            <a href="{{ route('issues.show', [$project, $entry->issue]) }}" class="text-indigo-600 hover:underline">
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
                            @if ($this->canManage)
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <a href="{{ route('time-entries.edit', [$project, $entry]) }}" class="text-indigo-600 hover:underline">編集</a>
                                    <button wire:click="deleteEntry({{ $entry->id }})" wire:confirm="この工数記録を削除しますか?" class="ml-2 text-red-600 hover:underline">削除</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + ($this->canManage ? 2 : 0) }}" class="px-4 py-6 text-center text-gray-500">工数記録がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
