<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IssueCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property IssueCategory $resource
 */
final class IssueCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->id,
            'project_id' => $category->project_id,
            'name' => $category->name,
            'assigned_to_id' => $category->assigned_to_id,
            'created_at' => $category->created_at->toIso8601String(),
            'updated_at' => $category->updated_at->toIso8601String(),
        ];
    }
}
