<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only, matching Redmine's own RolesController: roles are site-wide
 * admin-managed config, but both index and show are reachable by any
 * authenticated API request (require_admin_or_api_request), unlike the
 * admin-only web UI (RolePolicy, gated by Gate::before's admin bypass) —
 * so this deliberately does NOT call Gate::authorize against that
 * policy, same as TrackerController/IssueStatusController. index uses
 * the same Role::givable() scope Redmine's own index.api.rsb uses
 * (Role.givable), excluding the builtin Anonymous/NonMember placeholder
 * roles a project member could never actually be given; show has no
 * such filter, matching Redmine's find_role, which looks up any role
 * by id regardless. Every field here is returned on both actions
 * (Redmine's own index.api.rsb is deliberately minimal — id/name only —
 * but no resource in this app currently varies its shape between index
 * and show, so this doesn't start now). users_visibility is omitted —
 * this app doesn't implement that scope (see parity checklist).
 */
final class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::givable()->get();

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role);
    }
}
