<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single field set for every viewer, unlike Redmine's own admin-vs-self
 * vs-other split on show.api.rsb — this resource is only ever reachable
 * by an admin at all (UserPolicy denies everyone else, both index and
 * show, via the Gate::before admin bypass), so there is no reduced-field
 * audience to design for. `name` is a single combined field — this app
 * has no Redmine-style firstname/lastname split. Custom field values are
 * omitted, matching every other API resource in this app (none exposes
 * them yet).
 *
 * @property User $resource
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'login' => $user->login,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'status' => $user->status->value,
            'language' => $user->language,
            'auth_source_id' => $user->auth_source_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ];
    }
}
