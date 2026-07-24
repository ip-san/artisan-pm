<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IssueRelation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Same field shape as IssueResource::visibleRelations()'s embedded rows,
 * kept in sync deliberately — this is the standalone version of the same
 * data.
 *
 * @property IssueRelation $resource
 */
final class IssueRelationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $relation = $this->resource;

        return [
            'id' => $relation->id,
            'issue_id' => $relation->issue_from_id,
            'issue_to_id' => $relation->issue_to_id,
            'relation_type' => $relation->relation_type->value,
            'delay' => $relation->delay,
        ];
    }
}
