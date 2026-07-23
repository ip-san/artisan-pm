<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueRequest;
use App\Http\Requests\Api\V1\UpdateIssueRequest;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class IssueController extends Controller
{
    /**
     * The relations show's ?include= can request — Redmine's own keys,
     * minus allowed_statuses (needs the workflow engine) and changesets
     * (needs SCM linkage), both left out of scope here.
     *
     * @var array<int, string>
     */
    private const array SHOW_INCLUDES = ['journals', 'relations', 'attachments', 'children', 'watchers'];

    /**
     * Matches Redmine's own index action, which only ever honors
     * ?include=relations — every other key is show-only there too.
     *
     * @var array<int, string>
     */
    private const array INDEX_INCLUDES = ['relations'];

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        $query = Issue::query()->where('project_id', $project->id)->orderByDesc('id');

        if (in_array('relations', $this->parseIncludes($request, self::INDEX_INCLUDES), true)) {
            $query->with(['relationsFrom.to', 'relationsTo.from']);
        }

        /** @var LengthAwarePaginator<int, Issue> $issues */
        $issues = $query->paginate();

        return IssueResource::collection($issues);
    }

    public function show(Request $request, Issue $issue): IssueResource
    {
        Gate::authorize('view', $issue);

        $issue->load($this->relationsToLoad($this->parseIncludes($request, self::SHOW_INCLUDES)));

        return new IssueResource($issue);
    }

    public function store(StoreIssueRequest $request, Project $project): JsonResponse
    {
        $issue = app(IssueService::class)->create(
            [...$request->validated(), 'project_id' => $project->id, 'status_id' => $this->defaultStatusId()],
            $request->user(),
        );

        return (new IssueResource($issue))->response()->setStatusCode(201);
    }

    public function update(UpdateIssueRequest $request, Issue $issue): IssueResource
    {
        $data = $request->validated();

        if (isset($data['status_id']) && $data['status_id'] !== $issue->status_id) {
            Gate::authorize('transitionTo', [$issue, IssueStatus::findOrFail($data['status_id'])]);
        }

        $issue = app(IssueService::class)->update($issue, $data, $request->user());

        return new IssueResource($issue);
    }

    private function defaultStatusId(): int
    {
        return IssueStatus::query()->orderBy('position')->value('id');
    }

    /**
     * Comma-split, trimmed, and restricted to $allowed — matches Redmine's
     * own ApplicationHelper#include_in_api_response? parsing.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private function parseIncludes(Request $request, array $allowed): array
    {
        $requested = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $key) => trim($key))
            ->filter();

        return $requested->intersect($allowed)->values()->all();
    }

    /**
     * @param  array<int, string>  $includes
     * @return array<int, string>
     */
    private function relationsToLoad(array $includes): array
    {
        return collect($includes)->flatMap(fn (string $include) => match ($include) {
            'journals' => ['journals.user'],
            'relations' => ['relationsFrom.to', 'relationsTo.from'],
            'attachments' => ['media'],
            'children' => ['children'],
            'watchers' => ['watchers.user'],
            default => [],
        })->all();
    }
}
