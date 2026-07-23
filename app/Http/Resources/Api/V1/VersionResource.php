<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Version $resource
 */
final class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'id' => $version->id,
            'project_id' => $version->project_id,
            'name' => $version->name,
            'description' => $version->description,
            'status' => $version->status->value,
            'sharing' => $version->sharing->value,
            'due_date' => $version->due_date?->toDateString(),
            'created_at' => $version->created_at->toIso8601String(),
            'updated_at' => $version->updated_at->toIso8601String(),
        ];
    }
}
