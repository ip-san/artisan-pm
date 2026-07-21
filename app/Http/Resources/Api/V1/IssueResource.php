<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Issue $resource
 */
final class IssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issue = $this->resource;

        return [
            'id' => $issue->id,
            'project_id' => $issue->project_id,
            'tracker_id' => $issue->tracker_id,
            'status_id' => $issue->status_id,
            'priority_id' => $issue->priority_id,
            'author_id' => $issue->author_id,
            'assigned_to_id' => $issue->assigned_to_id,
            'fixed_version_id' => $issue->fixed_version_id,
            'parent_id' => $issue->parent_id,
            'subject' => $issue->subject,
            'description' => $issue->description,
            'start_date' => $issue->start_date?->toDateString(),
            'due_date' => $issue->due_date?->toDateString(),
            'done_ratio' => $issue->done_ratio,
            'created_at' => $issue->created_at->toIso8601String(),
            'updated_at' => $issue->updated_at->toIso8601String(),
        ];
    }
}
