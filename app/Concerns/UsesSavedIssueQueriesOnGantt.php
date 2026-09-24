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
 * Redmine's Gantt is drawn from an IssueQuery: the issue list's saved
 * queries are offered on the chart (loading one applies its filters) and
 * the chart's filters can be saved as an issue query, gated by
 * save_queries. The consuming Gantt component (with InteractsWithQueryFilters)
 * supplies ganttProject() (null for the cross-project chart) and
 * applyFilters().
 */
trait UsesSavedIssueQueriesOnGantt
{
    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int|string> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    abstract protected function ganttProject(): ?Project;

    /**
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        $project = $this->ganttProject();

        return $project === null
            ? SavedQuery::visibleGlobally(QueryType::Issue, auth()->user())
            : SavedQuery::visibleIn($project, QueryType::Issue, auth()->user());
    }

    #[Computed]
    public function canSaveQueries(): bool
    {
        $project = $this->ganttProject();

        return $project === null
            ? app(AuthorizationService::class)->canGlobally(auth()->user(), 'save_queries')
            : app(AuthorizationService::class)->can(auth()->user(), 'save_queries', $project);
    }

    #[Computed]
    public function canManagePublicQueries(): bool
    {
        $project = $this->ganttProject();

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
     * list columns), as Redmine's Gantt "Save" does.
     */
    public function saveQuery(): void
    {
        abort_unless($this->canSaveQueries, 403);

        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $project = $this->ganttProject();
        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $project);

        $query = SavedQuery::create([
            'name' => $data['newQueryName'],
            'type' => QueryType::Issue->value,
            'user_id' => auth()->id(),
            'project_id' => $project?->id,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            'column_names' => Setting::get('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']),
            'sort_criteria' => [],
            'group_by' => null,
        ]);

        if ($visibility === QueryVisibility::Roles->value) {
            $query->roles()->sync($data['newQueryRoleIds']);
        }

        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', 'showSaveForm']);
        unset($this->savedQueries);
        session()->flash('status', __('クエリを保存しました。'));
    }
}
