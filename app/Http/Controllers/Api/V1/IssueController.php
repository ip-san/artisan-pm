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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class IssueController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        /** @var LengthAwarePaginator<int, Issue> $issues */
        $issues = Issue::query()
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->paginate();

        return IssueResource::collection($issues);
    }

    public function show(Issue $issue): IssueResource
    {
        Gate::authorize('view', $issue);

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
}
