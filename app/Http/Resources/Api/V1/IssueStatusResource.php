<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IssueStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property IssueStatus $resource
 */
final class IssueStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->resource;

        return [
            'id' => $status->id,
            'name' => $status->name,
            'is_closed' => $status->is_closed,
            'default_done_ratio' => $status->default_done_ratio,
        ];
    }
}
