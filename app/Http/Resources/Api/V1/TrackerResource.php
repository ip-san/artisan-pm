<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tracker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Tracker $resource
 */
final class TrackerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tracker = $this->resource;

        return [
            'id' => $tracker->id,
            'name' => $tracker->name,
            'description' => $tracker->description,
            'default_status_id' => $tracker->default_status_id,
            'is_in_roadmap' => $tracker->is_in_roadmap,
        ];
    }
}
