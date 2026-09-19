<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Project $resource
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        // Single-project responses (show/store/update) load these here; the
        // index eager-loads them so the list stays free of N+1 queries.
        $project->loadMissing(['defaultVersion', 'defaultAssignedTo']);

        return [
            'id' => $project->id,
            'identifier' => $project->identifier,
            'name' => $project->name,
            'description' => $project->description,
            'homepage' => $project->homepage,
            'is_public' => $project->is_public,
            'status' => $project->status->value,
            'parent_id' => $project->parent_id,
            'default_version' => $project->defaultVersion !== null
                ? ['id' => $project->defaultVersion->id, 'name' => $project->defaultVersion->name]
                : null,
            'default_assignee' => $project->defaultAssignedTo !== null
                ? ['id' => $project->defaultAssignedTo->id, 'name' => $project->defaultAssignedTo->name]
                : null,
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ];
    }
}
