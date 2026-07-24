<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Enumeration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches Redmine's enumerations index.api.rsb field shape exactly:
 * id/name/is_default/active — no position, type, or custom field values
 * (IssuePriority has no custom fields in Redmine either way, and no
 * API resource in this app exposes custom field values yet).
 *
 * @property Enumeration $resource
 */
final class EnumerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $enumeration = $this->resource;

        return [
            'id' => $enumeration->id,
            'name' => $enumeration->name,
            'is_default' => $enumeration->is_default,
            'active' => $enumeration->active,
        ];
    }
}
