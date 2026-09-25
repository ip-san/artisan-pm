<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Redmine draws both the Gantt chart and the calendar from an IssueQuery:
 * the issue list's saved queries are offered there too (loading one
 * applies its filters) and the current filters can be saved as an issue
 * query, gated by save_queries. Only filters apply — unlike the issue
 * list's own saveQuery()/loadQuery(), columns/sort/group_by are not read
 * or written, since neither view has any of those. The consuming
 * component (with InteractsWithQueryFilters) supplies
 * queryScopeProject() (null for a cross-project view) and applyFilters().
 */
trait UsesSavedIssueQueriesForFiltering
{
    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int|string> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * Set by editQuery() while the save form is prefilled with an
     * existing query's settings, for saveQuery() to update in place
     * instead of creating a new one — Redmine's QueriesController#edit
     * (A15-07b, the issue list's own edit/delete UI wired in here too).
     */
    public ?int $editingQueryId = null;

    abstract protected function queryScopeProject(): ?Project;

    /**
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        $project = $this->queryScopeProject();

        return $project === null
            ? SavedQuery::visibleGlobally(QueryType::Issue, auth()->user())
            : SavedQuery::visibleIn($project, QueryType::Issue, auth()->user());
    }

    #[Computed]
    public function canSaveQueries(): bool
    {
        $project = $this->queryScopeProject();

        return $project === null
            ? app(AuthorizationService::class)->canGlobally(auth()->user(), 'save_queries')
            : app(AuthorizationService::class)->can(auth()->user(), 'save_queries', $project);
    }

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        $project = $this->queryScopeProject();

        return $project === null
            ? auth()->user()?->is_admin === true
            : app(AuthorizationService::class)->can(auth()->user(), 'manage_public_queries', $project);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()->givable()->get();
    }

    /**
     * Applies a saved issue query's filters to the chart (its columns and
     * sort do not apply to a Gantt chart).
     */
    public function loadQuery(int $queryId): void
    {
        $query = $this->savedQueries->firstWhere('id', $queryId);

        abort_if($query === null, 404);

        $this->activeFilterKeys = array_keys($query->filters ?? []);
        $this->filterOperators = [];
        $this->filterValues = [];

        foreach ($query->filters ?? [] as $key => $filter) {
            $this->filterOperators[$key] = $filter['operator'];
            $this->filterValues[$key] = $filter['values'] ?? [];
        }

        $this->applyFilters();
    }

    /**
     * Saves the chart's filters as an issue query (with the site's default
     * list columns), as Redmine's Gantt "Save" does — or, with
     * editingQueryId set (editQuery()), updates that query in place
     * instead of creating a new one.
     *
     * editing keeps the query's *existing* project scope rather than
     * recomputing it from queryScopeProject(): this trait has no
     * query_is_for_all-style toggle (unlike the issue list's own
     * saveQuery()), so editing a global query from inside a specific
     * project's Gantt (or vice versa) must not silently move it between
     * scopes just because of where the edit happened to be opened from.
     */
    public function saveQuery(): void
    {
        abort_unless($this->canSaveQueries, 403);

        $editing = $this->editingQueryId !== null ? SavedQuery::findOrFail($this->editingQueryId) : null;

        if ($editing !== null) {
            $this->authorize('update', $editing);
        }

        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $project = $editing !== null
            ? ($editing->project_id !== null ? Project::find($editing->project_id) : null)
            : $this->queryScopeProject();
        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $project);

        $attributes = [
            'name' => $data['newQueryName'],
            'project_id' => $project?->id,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => Setting::get('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']),
            'sort_criteria' => [],
            'group_by' => null,
        ];

        if ($editing !== null) {
            $editing->update($attributes);
            $query = $editing;
        } else {
            $query = SavedQuery::create([...$attributes, 'type' => QueryType::Issue->value, 'user_id' => auth()->id()]);
        }

        $query->roles()->sync($visibility === QueryVisibility::Roles->value ? $data['newQueryRoleIds'] : []);

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', $editing !== null ? __('クエリを更新しました。') : __('クエリを保存しました。'));
    }

    /**
     * Opens the save form prefilled with an existing saved query's
     * settings, for saveQuery() to update in place — Redmine's
     * QueriesController#edit, mirrored from the issue list's own
     * editQuery().
     */
    public function editQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::findOrFail($queryId);
        $this->authorize('update', $query);

        $this->loadQuery($queryId);
        $this->editingQueryId = $query->id;
        $this->newQueryName = $query->name;
        $this->newQueryVisibility = $query->visibility->value;
        $this->newQueryRoleIds = $query->roles->pluck('id')->all();
        $this->showSaveForm = true;
    }

    public function cancelEditQuery(): void
    {
        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'editingQueryId', 'showSaveForm']);
    }

    public function deleteQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = SavedQuery::findOrFail($queryId);
        $this->authorize('delete', $query);

        $query->delete();

        if ($this->editingQueryId === $queryId) {
            $this->cancelEditQuery();
        }

        unset($this->savedQueries);
        session()->flash('status', __('クエリを削除しました。'));
    }
}
