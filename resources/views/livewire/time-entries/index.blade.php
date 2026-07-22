<?php

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\TimeEntryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
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

    /** @var array<int, string> */
    #[Url]
    public array $activeFilterKeys = [];

    /** @var array<string, string> */
    public array $filterOperators = [];

    /** @var array<string, array<int, mixed>> */
    public array $filterValues = [];

    #[Url]
    public ?string $sortKey = 'spent_on';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public ?string $groupBy = null;

    /** @var array<int, string> */
    #[Url]
    public array $columns = ['spent_on', 'user_id', 'activity_id', 'issue_id', 'comments', 'hours'];

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

    /**
     * @return array<string, array{operator: string, values: array<int, mixed>}>
     */
    private function builtFilters(): array
    {
        $filters = [];

        foreach ($this->activeFilterKeys as $key) {
            $operator = $this->filterOperators[$key] ?? null;

            if ($operator === null) {
                continue;
            }

            $filters[$key] = [
                'operator' => $operator,
                'values' => array_values(array_filter($this->filterValues[$key] ?? [], fn ($v) => $v !== null && $v !== '')),
            ];
        }

        return $filters;
    }

    public function addFilter(string $key): void
    {
        if (! in_array($key, $this->activeFilterKeys, true) && $this->engine->field($key) !== null) {
            $this->activeFilterKeys[] = $key;
            $this->filterOperators[$key] = $this->engine->field($key)->operators()[0]->value;
        }
    }

    public function removeFilter(string $key): void
    {
        $this->activeFilterKeys = array_values(array_diff($this->activeFilterKeys, [$key]));
        unset($this->filterOperators[$key], $this->filterValues[$key]);
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
        return Role::query()->whereNull('builtin')->orderBy('position')->get();
    }

    public function saveQuery(): void
    {
        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        // Only a manage_public_queries holder can make a query anything
        // but private — matches Redmine's QueriesController#new/#create,
        // which silently forces VISIBILITY_PRIVATE for anyone else rather
        // than rejecting the submission outright.
        $visibility = $this->canManagePublicQueries ? $data['newQueryVisibility'] : QueryVisibility::Private->value;

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
        $query = SavedQuery::query()->where('project_id', $this->project->id)->findOrFail($queryId);

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

    /**
     * Roles-scoped visibility needs a role-intersection check that isn't
     * a single SQL predicate, so this filters in memory after the fact —
     * the same approach projects.index uses for its own can('view')
     * filter, and small enough per project to not matter.
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        return SavedQuery::query()
            ->where('project_id', $this->project->id)
            ->where('type', QueryType::TimeEntry->value)
            ->orderBy('name')
            ->get()
            ->filter(fn (SavedQuery $query) => $query->visibleTo(auth()->user()))
            ->values();
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

        $entry->delete();

        unset($this->timeEntries, $this->groupedTimeEntries);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', [TimeEntry::class, $this->project]);

        $query = $this->filteredTimeEntriesQuery();
        $columns = $this->columns;

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_map(fn (string $key) => self::DISPLAY_COLUMNS[$key] ?? $key, $columns));

            $query->chunk(200, function ($chunk) use ($handle, $columns) {
                foreach ($chunk as $entry) {
                    fputcsv($handle, array_map(fn (string $key) => $this->columnValue($entry, $key), $columns));
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
        <div class="flex gap-2">
            <button wire:click="exportCsv" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                CSVエクスポート
            </button>
            @can('create', [\App\Models\TimeEntry::class, $project])
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
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-gray-700">フィルタを追加:</span>
            @foreach ($this->engine->fields() as $field)
                @unless (in_array($field->key(), $activeFilterKeys, true))
                    <button wire:key="add-filter-{{ $field->key() }}" wire:click="addFilter('{{ $field->key() }}')" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">
                        + {{ $field->label() }}
                    </button>
                @endunless
            @endforeach
        </div>

        @if ($activeFilterKeys !== [])
            <div class="space-y-2">
                @foreach ($activeFilterKeys as $key)
                    @php $field = $this->engine->field($key); @endphp
                    @continue(! $field)
                    <div wire:key="filter-row-{{ $key }}" class="flex flex-wrap items-center gap-2">
                        <span class="w-28 text-sm text-gray-700">{{ $field->label() }}</span>
                        <select wire:model="filterOperators.{{ $key }}" class="rounded-md border-gray-300 text-sm">
                            @foreach ($field->operators() as $operator)
                                <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                            @endforeach
                        </select>

                        @if (($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsEmpty->value && ($filterOperators[$key] ?? null) !== \App\Enums\FilterOperator::IsNotEmpty->value)
                            @if ($field->type() === \App\Enums\FilterFieldType::Select && $field->options() !== [])
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::In->value || ($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::NotIn->value)
                                    <select wire:model="filterValues.{{ $key }}" multiple class="min-w-[10rem] rounded-md border-gray-300 text-sm">
                                        @foreach ($field->options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <select wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                                        <option value="">選択してください</option>
                                        @foreach ($field->options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            @elseif ($field->type() === \App\Enums\FilterFieldType::Date)
                                <input type="date" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                                    <span class="text-gray-400">〜</span>
                                    <input type="date" wire:model="filterValues.{{ $key }}.1" class="rounded-md border-gray-300 text-sm">
                                @endif
                            @elseif ($field->type() === \App\Enums\FilterFieldType::Integer)
                                {{-- step="0.01" also covers the "hours" field, the one decimal
                                     value filterable here — FilterFieldType has no distinct
                                     decimal case yet, and any whole number is still a valid
                                     multiple of 0.01, so this doesn't affect true integer fields. --}}
                                <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.0" class="w-24 rounded-md border-gray-300 text-sm">
                                @if (($filterOperators[$key] ?? null) === \App\Enums\FilterOperator::Between->value)
                                    <span class="text-gray-400">〜</span>
                                    <input type="number" step="0.01" wire:model="filterValues.{{ $key }}.1" class="w-24 rounded-md border-gray-300 text-sm">
                                @endif
                            @else
                                <input type="text" wire:model="filterValues.{{ $key }}.0" class="rounded-md border-gray-300 text-sm">
                            @endif
                        @endif

                        <button wire:click="removeFilter('{{ $key }}')" class="text-xs text-red-600 hover:underline">削除</button>
                    </div>
                @endforeach
            </div>
        @endif

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
            <form wire:submit="saveQuery" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                <input type="text" wire:model="newQueryName" placeholder="クエリ名" class="rounded-md border-gray-300 text-sm">

                @if ($this->canManagePublicQueries)
                    <select wire:model.live="newQueryVisibility" class="rounded-md border-gray-300 text-sm">
                        <option value="private">非公開</option>
                        <option value="roles">特定ロールに公開</option>
                        <option value="public">全員に公開</option>
                    </select>

                    @if ($newQueryVisibility === 'roles')
                        <span class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                            @foreach ($this->availableRoles as $role)
                                <label class="flex items-center gap-1">
                                    <input type="checkbox" wire:model="newQueryRoleIds" value="{{ $role->id }}" class="rounded border-gray-300">
                                    {{ $role->name }}
                                </label>
                            @endforeach
                        </span>
                        @error('newQueryRoleIds') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    @endif
                @else
                    <span class="text-xs text-gray-500">(非公開クエリとして保存されます)</span>
                @endif

                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">保存</button>
                @error('newQueryName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </form>
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
                        @if ($this->canManage)
                            <th class="px-4 py-2"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groupEntries as $entry)
                        <tr wire:key="time-entry-{{ $entry->id }}">
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
                            <td colspan="{{ count($columns) + 1 }}" class="px-4 py-6 text-center text-gray-500">工数記録がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
