<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ProjectController extends Controller
{
    /**
     * Mirrors the projects.index Livewire component's own visibility
     * filtering — there's no blanket "view any project" permission, only a
     * per-project one, so every candidate is checked individually.
     */
    public function index(): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => Gate::allows('view', $project))
            ->values();

        return ProjectResource::collection($projects);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project);
    }
}
