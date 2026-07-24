<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\QueryVisibility;
use App\Models\Query;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches Redmine's queries/index.api.rsb field shape exactly: id/name/
 * is_public/project_id — no `type`, even though this app uses a `type`
 * column (Issue/TimeEntry) instead of Redmine's STI subclasses to select
 * which queries are being listed; Redmine's own response never emits
 * `type` either despite the request being scoped by it.
 *
 * @property Query $resource
 */
final class QueryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $query = $this->resource;

        return [
            'id' => $query->id,
            'name' => $query->name,
            'is_public' => $query->visibility === QueryVisibility::Public,
            'project_id' => $query->project_id,
        ];
    }
}
