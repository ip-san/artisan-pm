<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Role $resource
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'assignable' => $role->assignable,
            'issues_visibility' => $role->issues_visibility->value,
            'time_entries_visibility' => $role->time_entries_visibility->value,
            'permissions' => $role->permissionKeys(),
        ];
    }
}
