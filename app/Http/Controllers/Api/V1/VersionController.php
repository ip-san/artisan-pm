<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVersionRequest;
use App\Http\Requests\Api\V1\UpdateVersionRequest;
use App\Http\Resources\Api\V1\VersionResource;
use App\Models\Project;
use App\Models\Version;
use App\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class VersionController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Version::class, $project]);

        $versions = Version::query()->where('project_id', $project->id)->orderBy('name')->paginate();

        return VersionResource::collection($versions);
    }

    public function show(Version $version): VersionResource
    {
        Gate::authorize('view', $version);

        return new VersionResource($version);
    }

    public function store(StoreVersionRequest $request, Project $project): JsonResponse
    {
        $version = app(VersionService::class)->create([...$request->validated(), 'project_id' => $project->id]);

        return (new VersionResource($version))->response()->setStatusCode(201);
    }

    public function update(UpdateVersionRequest $request, Version $version): VersionResource
    {
        $version = app(VersionService::class)->update($version, $request->validated());

        return new VersionResource($version);
    }

    public function destroy(Version $version): JsonResponse
    {
        Gate::authorize('delete', $version);

        app(VersionService::class)->delete($version);

        return response()->json(status: 204);
    }
}
