<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TimeEntry $resource
 */
final class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timeEntry = $this->resource;

        return [
            'id' => $timeEntry->id,
            'project_id' => $timeEntry->project_id,
            'issue_id' => $timeEntry->issue_id,
            'user_id' => $timeEntry->user_id,
            'activity_id' => $timeEntry->activity_id,
            'hours' => (float) $timeEntry->hours,
            'spent_on' => $timeEntry->spent_on->toDateString(),
            'comments' => $timeEntry->comments,
            'created_at' => $timeEntry->created_at->toIso8601String(),
            'updated_at' => $timeEntry->updated_at->toIso8601String(),
        ];
    }
}
