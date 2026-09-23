<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat project_id/user_id/group_id/role_ids, matching this app's own
 * established resource convention (IssueCategoryResource/VersionResource/
 * GroupResource) rather than Redmine's own nested {id, name} project/user/
 * group/role objects. Redmine marks an inherited role with
 * `inherited: true` inside its roles array; the flat equivalent here is
 * inherited_role_ids, the subset of role_ids copied from the parent project
 * (projects.inherit_members), which cannot be removed in this project.
 *
 * @property Member $resource
 */
final class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;

        return [
            'id' => $member->id,
            'project_id' => $member->project_id,
            'user_id' => $member->user_id,
            'group_id' => $member->group_id,
            'role_ids' => $member->roles->pluck('id')->unique()->values()->all(),
            'inherited_role_ids' => $member->inheritedRoleIds()->all(),
            'created_at' => $member->created_at->toIso8601String(),
            'updated_at' => $member->updated_at->toIso8601String(),
        ];
    }
}
