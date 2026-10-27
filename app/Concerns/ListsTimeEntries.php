<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\QueryType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Format\DateTimes;
use App\Support\Format\Hours;
use App\Support\Query\TimeEntryColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The list side shared by a project's time entry list and the
 * cross-project one (Redmine's TimelogController#index): the filtered and
 * sorted entries, their grouping and totals, the selectable columns and
 * each cell's text, and what a saved query stores. The component provides
 * the projects the list covers (timeEntryListProjects()), how the hours
 * cell reads (hoursCell()), the $columns/$groupBy/$sortKey/$sortDirection
 * state and the computed engine(); it uses InteractsWithQueryFilters and
 * ManagesSavedQueries.
 */
trait ListsTimeEntries
{
    /** @var array<int, bool> issue id => whether the viewer may see it */
    private array $issueVisibility = [];

    /**
     * The projects whose entries the list shows.
     *
     * @return Collection<int, Project>
     */
    abstract protected function timeEntryListProjects(): Collection;

    abstract protected function hoursCell(TimeEntry $entry): string;

    /**
     * The issue relation eager-loaded with every entry.
     */
    protected function timeEntryIssueRelation(): string
    {
        return 'issue';
    }

    /**
     * Translated header labels for DISPLAY_COLUMNS (constants can't call __()).
     *
     * @return array<string, string>
     */
    public function displayColumnLabels(): array
    {
        return [
            'project_id' => __('プロジェクト'),
            'spent_on' => __('日付'),
            'created_at' => __('作成日'),
            'tweek' => __('週'),
            'author_id' => __('作成者'),
            'user_id' => __('ユーザー'),
            'activity_id' => __('作業分類'),
            'issue_id' => __('課題'),
            'comments' => __('コメント'),
            'hours' => __('時間'),
        ];
    }

    protected function savedQueryType(): QueryType
    {
        return QueryType::TimeEntry;
    }

    /**
     * @return array<string, mixed>
     */
    protected function savedQueryAttributes(): array
    {
        return [
            'column_names' => $this->columns,
            'sort_criteria' => $this->sortKey ? [[$this->sortKey, $this->sortDirection]] : [],
            'group_by' => $this->groupBy,
        ];
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filteredTimeEntriesQuery(): Builder
    {
        $query = TimeEntry::query()
            ->visibleToAcrossProjects(auth()->user(), $this->timeEntryListProjects())
            ->with(['project', 'user', 'author', 'activity', $this->timeEntryIssueRelation(), 'customFieldValues', ...TimeEntryColumns::relations([...$this->columns, ...($this->groupBy !== null ? [$this->groupBy] : [])])]);

        $query = $this->engine->applyFilters($query, $this->builtFilters());

        // The issue and custom field columns sort through TimeEntryColumns,
        // which sees only what the viewer may see; the rest through the engine.
        if ($this->sortKey !== null) {
            $sortedByExtraColumn = TimeEntryColumns::handles($this->sortKey)
                && array_key_exists($this->sortKey, $this->availableColumns)
                && $this->extraColumns->applySort($query, $this->sortKey, $this->sortDirection);

            if (! $sortedByExtraColumn) {
                $query = $this->engine->applySort($query, [[$this->sortKey, $this->sortDirection]]);
            }
        } else {
            $query->orderByDesc('spent_on');
        }

        return $query;
    }

    /**
     * One page of the filtered entries; the totals and the group subtotals
     * below still cover every matching entry.
     *
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    #[Computed]
    public function timeEntries(): LengthAwarePaginator
    {
        return $this->filteredTimeEntriesQuery()->paginate($this->pageSize());
    }

    /**
     * @return Collection<string, EloquentCollection<int, TimeEntry>>
     */
    #[Computed]
    public function groupedTimeEntries(): Collection
    {
        $entries = $this->timeEntries->getCollection();

        if ($this->groupBy === null) {
            return collect(['' => $entries]);
        }

        return $entries->groupBy(fn (TimeEntry $entry) => $this->columnValue($entry, $this->groupBy));
    }

    /**
     * Entry count and hours of every group across all pages (Redmine's group
     * totals), keyed like groupedTimeEntries. Only computed while grouping,
     * which reads every matching entry.
     *
     * @return array<string, array{count: int, hours: string}>
     */
    #[Computed]
    public function groupSubtotals(): array
    {
        if ($this->groupBy === null) {
            return [];
        }

        return $this->filteredTimeEntriesQuery()->get()
            ->groupBy(fn (TimeEntry $entry) => $this->columnValue($entry, $this->groupBy))
            ->map(fn (EloquentCollection $entries) => [
                'count' => $entries->count(),
                'hours' => Hours::format((float) $entries->sum('hours')),
            ])
            ->all();
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
     * The selectable columns: the fixed ones plus the issue's attributes and
     * the custom field columns the viewer may see in a project the list
     * covers; each row shows a field only where the viewer may see it
     * (TimeEntryColumns).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableColumns(): array
    {
        return [...$this->displayColumnLabels(), ...$this->extraColumns->labels()];
    }

    #[Computed]
    public function extraColumns(): TimeEntryColumns
    {
        return new TimeEntryColumns(auth()->user(), $this->timeEntryListProjects());
    }

    /**
     * Whether the viewer may see the entry's issue: an entry logged on an
     * issue the viewer cannot see shows only its number, as in Redmine
     * (format_object links an issue only when it is visible).
     */
    public function issueIsVisible(TimeEntry $entry): bool
    {
        if ($entry->issue === null) {
            return false;
        }

        return $this->issueVisibility[$entry->issue->id] ??= Gate::allows('view', $entry->issue);
    }

    public function columnValue(TimeEntry $entry, string $key): string
    {
        if (TimeEntryColumns::handles($key)) {
            // Only a column the viewer is offered: a URL or saved query
            // naming a role-restricted field shows nothing.
            return array_key_exists($key, $this->availableColumns) ? $this->extraColumns->value($entry, $key) : '';
        }

        return match ($key) {
            'project_id' => $entry->project->name,
            'user_id' => $entry->user->displayName(),
            'activity_id' => $entry->activity->name,
            'spent_on' => DateTimes::date($entry->spent_on),
            'created_at' => DateTimes::dateTime($entry->created_at) ?? '',
            'tweek' => (string) $entry->spent_on->isoWeek(),
            'author_id' => $entry->author?->displayName() ?? '',
            'hours' => $this->hoursCell($entry),
            'issue_id' => $entry->issue ? ($this->issueIsVisible($entry) ? "#{$entry->issue->id} {$entry->issue->subject}" : "#{$entry->issue->id}") : '',
            'comments' => (string) $entry->comments,
            default => '',
        };
    }

    #[Computed]
    public function totalHours(): string
    {
        return Hours::format((float) $this->filteredTimeEntriesQuery()->reorder()->sum('time_entries.hours'));
    }
}
