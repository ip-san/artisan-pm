<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\Api\V1\SearchResultResource;
use App\Models\Project;
use App\Models\User;
use App\Services\SearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Mirrors the existing search.global-index / search.index Volt
 * components' own project-visibility resolution exactly (a full project
 * scan filtered by $user->can('view', $project)) — SearchService itself
 * only narrows further per result type (view_issues/view_wiki_pages/
 * etc.), it doesn't check "can view this project" at all, so the caller
 * must pre-filter, same as those Volt components do. This same
 * scan-and-filter idiom already appears in several other Livewire
 * components beyond search (issues/time-entries global-index) — a
 * shared Project::visibleTo()-style helper would be a reasonable follow
 * -up to centralize all of them, but migrating already-shipped,
 * separately-tested UI components is out of scope for wrapping
 * SearchService in this API; visibleProjects() below only dedupes the
 * two copies this controller itself would otherwise have.
 *
 * No pagination: SearchService already caps each of its 7 result types
 * at 20 (RESULTS_PER_TYPE) before merging, and neither Volt component
 * paginates the merged ~140-result ceiling either — this mirrors that
 * existing behavior rather than inventing an offset/limit scheme Redmine
 * has but nothing else in this app's search feature does.
 */
final class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function index(SearchRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $data = $request->validated();

        // Narrowing to the user's own projects before the (expensive,
        // per-project) view-permission check is cheaper than checking
        // every project in the system and intersecting afterward —
        // set-intersection order doesn't affect the result, only the cost.
        $query = ($data['scope'] ?? 'all') === 'my_projects'
            ? Project::query()->whereIn('id', $user->projects()->pluck('projects.id'))
            : Project::query();

        return $this->search($this->visibleProjects($query, $user), $user, $data);
    }

    public function forProject(SearchRequest $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        $user = $request->user();
        $data = $request->validated();

        $projects = ($data['subprojects'] ?? false)
            ? $this->visibleProjects(Project::query()->where('_lft', '>=', $project->_lft)->where('_rgt', '<=', $project->_rgt), $user)
            : collect([$project]);

        return $this->search($projects, $user, $data);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Collection<int, Project>
     */
    private function visibleProjects(Builder $query, User $user): Collection
    {
        return $query->get()->filter(fn (Project $project) => $user->can('view', $project))->values();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $data
     */
    private function search(Collection $projects, User $user, array $data): AnonymousResourceCollection
    {
        $results = $this->searchService->searchAcrossProjects(
            $projects,
            $user,
            $data['q'] ?? '',
            $data['all_words'] ?? true,
            $data['titles_only'] ?? false,
            $data['open_issues'] ?? false,
        );

        return SearchResultResource::collection($results);
    }
}
