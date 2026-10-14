<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The saved-query side shared by every list backed by a Query (Redmine's
 * QueriesController): the queries offered on the list, the save form and
 * its create/edit/delete actions, gated by save_queries. Each list says
 * which kind of query it keeps (savedQueryType()), whose list it is
 * (queryScopeProject(), null for a cross-project one), what it stores
 * besides the filters (savedQueryAttributes()) and how a query is loaded
 * back (loadQuery()); the other hooks have the common answer as their
 * default.
 */
trait ManagesSavedQueries
{
    public string $newQueryName = '';

    public string $newQueryVisibility = 'private';

    /** @var array<int, int|string> */
    public array $newQueryRoleIds = [];

    public bool $showSaveForm = false;

    /**
     * Set by editQuery() while the save form is prefilled with an existing
     * query's settings, for saveQuery() to update it in place instead of
     * creating a new one — Redmine's QueriesController#edit/#update
     * (A15-07b).
     */
    public ?int $editingQueryId = null;

    abstract protected function savedQueryType(): QueryType;

    /**
     * The project whose list this is; null for a cross-project list, whose
     * queries are the global ones.
     */
    abstract protected function queryScopeProject(): ?Project;

    /**
     * What the list stores in a query besides its filters (columns, sort,
     * grouping, options), written on create and on edit alike.
     *
     * @return array<string, mixed>
     */
    abstract protected function savedQueryAttributes(): array;

    abstract public function loadQuery(int $queryId): void;

    /**
     * Attributes written only when a query is created.
     *
     * @return array<string, mixed>
     */
    protected function savedQueryCreateAttributes(): array
    {
        return [];
    }

    /**
     * The project a query is saved under. A new query belongs to the list's
     * project; an edited one stays under whatever project it already had
     * (possibly none, or in principle another project's, since
     * editableBy() doesn't require it be this project's own): a list with
     * no query_is_for_all-style toggle must never silently move a query
     * between scopes just because of where the edit happened to be opened
     * from.
     */
    protected function savedQueryTargetProject(?SavedQuery $editing): ?Project
    {
        if ($editing === null) {
            return $this->queryScopeProject();
        }

        return $editing->project_id !== null ? Project::find($editing->project_id) : null;
    }

    /**
     * The saved query an edit or a delete acts on.
     */
    protected function findEditableQuery(int $queryId): SavedQuery
    {
        return SavedQuery::where('type', $this->savedQueryType()->value)->findOrFail($queryId);
    }

    /**
     * The list's own save-form fields, reset together with the common ones.
     *
     * @return array<int, string>
     */
    protected function savedQueryFormFields(): array
    {
        return [];
    }

    /**
     * Prefills the list's own save-form fields from the query being edited.
     */
    protected function prefillSavedQueryForm(SavedQuery $query): void {}

    /**
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function savedQueries(): Collection
    {
        $project = $this->queryScopeProject();

        return $project === null
            ? SavedQuery::visibleGlobally($this->savedQueryType(), auth()->user())
            : SavedQuery::visibleIn($project, $this->savedQueryType(), auth()->user());
    }

    /**
     * Whether the viewer may save queries (Redmine's save_queries).
     */
    #[Computed]
    public function canSaveQueries(): bool
    {
        $project = $this->queryScopeProject();

        return $project === null
            ? app(AuthorizationService::class)->canGlobally(auth()->user(), 'save_queries')
            : app(AuthorizationService::class)->can(auth()->user(), 'save_queries', $project);
    }

    /**
     * Whether the viewer may share a query beyond themselves: a global
     * query only an administrator may (Query::resolveVisibility() with no
     * project), a project's one whoever holds manage_public_queries there.
     */
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

    public function saveQuery(): void
    {
        // Redmine's save_queries permission gates new/create/edit/update/
        // destroy together (lib/redmine/preparation.rb); editable_by?
        // (via the update policy) narrows further to this particular query.
        abort_unless($this->canSaveQueries, 403);

        $editing = $this->editingQueryId !== null ? $this->findEditableQuery($this->editingQueryId) : null;

        if ($editing !== null) {
            $this->authorize('update', $editing);
        }

        $data = $this->validate([
            'newQueryName' => ['required', 'string', 'max:255'],
            'newQueryVisibility' => ['required', Rule::enum(QueryVisibility::class)],
            'newQueryRoleIds' => $this->newQueryVisibility === QueryVisibility::Roles->value ? ['required', 'array', 'min:1'] : ['array'],
            'newQueryRoleIds.*' => ['exists:roles,id'],
        ]);

        $project = $this->savedQueryTargetProject($editing);
        $visibility = SavedQuery::resolveVisibility(auth()->user(), $data['newQueryVisibility'], $project);

        $attributes = [
            'name' => $data['newQueryName'],
            'project_id' => $project?->id,
            'visibility' => $visibility,
            'filters' => $this->builtFilters(),
            ...$this->savedQueryAttributes(),
        ];

        if ($editing !== null) {
            $editing->update($attributes);
            $query = $editing;
        } else {
            $query = SavedQuery::create([
                ...$attributes,
                'type' => $this->savedQueryType()->value,
                'user_id' => auth()->id(),
                ...$this->savedQueryCreateAttributes(),
            ]);
        }

        $query->roles()->sync($visibility === QueryVisibility::Roles->value ? $data['newQueryRoleIds'] : []);

        $this->resetSaveForm();
        unset($this->savedQueries);
        session()->flash('status', $editing !== null ? __('クエリを更新しました。') : __('クエリを保存しました。'));
    }

    /**
     * Opens the save form prefilled with an existing saved query's
     * settings, for saveQuery() to update in place — Redmine's
     * QueriesController#edit.
     */
    public function editQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = $this->findEditableQuery($queryId);
        $this->authorize('update', $query);

        $this->loadQuery($queryId);
        $this->editingQueryId = $query->id;
        $this->newQueryName = $query->name;
        $this->newQueryVisibility = $query->visibility->value;
        $this->newQueryRoleIds = $query->roles->pluck('id')->all();
        $this->prefillSavedQueryForm($query);
        $this->showSaveForm = true;
    }

    public function cancelEditQuery(): void
    {
        $this->resetSaveForm();
    }

    public function deleteQuery(int $queryId): void
    {
        abort_unless($this->canSaveQueries, 403);

        $query = $this->findEditableQuery($queryId);
        $this->authorize('delete', $query);

        $query->delete();

        if ($this->editingQueryId === $queryId) {
            $this->cancelEditQuery();
        }

        unset($this->savedQueries);
        session()->flash('status', __('クエリを削除しました。'));
    }

    private function resetSaveForm(): void
    {
        $this->reset(['newQueryName', 'newQueryVisibility', 'newQueryRoleIds', ...$this->savedQueryFormFields(), 'editingQueryId', 'showSaveForm']);
    }
}
