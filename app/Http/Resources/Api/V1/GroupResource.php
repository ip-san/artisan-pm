<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Group $resource
 */
final class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'user_ids' => $this->whenLoaded('users', fn () => $group->users->pluck('id')->all()),
            'created_at' => $group->created_at->toIso8601String(),
            'updated_at' => $group->updated_at->toIso8601String(),
        ];
    }
}
