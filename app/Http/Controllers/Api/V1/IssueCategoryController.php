<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueCategoryRequest;
use App\Http\Requests\Api\V1\UpdateIssueCategoryRequest;
use App\Http\Resources\Api\V1\IssueCategoryResource;
use App\Models\IssueCategory;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class IssueCategoryController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [IssueCategory::class, $project]);

        $categories = IssueCategory::query()->where('project_id', $project->id)->orderBy('name')->paginate();

        return IssueCategoryResource::collection($categories);
    }

    public function show(IssueCategory $issueCategory): IssueCategoryResource
    {
        Gate::authorize('view', $issueCategory);

        return new IssueCategoryResource($issueCategory);
    }

    public function store(StoreIssueCategoryRequest $request, Project $project): JsonResponse
    {
        $category = new IssueCategory($request->validated());
        $category->project()->associate($project);
        $category->save();

        return (new IssueCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateIssueCategoryRequest $request, IssueCategory $issueCategory): IssueCategoryResource
    {
        $issueCategory->update($request->validated());

        return new IssueCategoryResource($issueCategory);
    }

    public function destroy(IssueCategory $issueCategory): JsonResponse
    {
        Gate::authorize('delete', $issueCategory);

        $issueCategory->delete();

        return response()->json(status: 204);
    }
}
