<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMembershipRequest;
use App\Http\Requests\Api\V1\UpdateMembershipRequest;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Models\Member;
use App\Models\Project;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Every action is gated behind manage_members (MemberPolicy), matching
 * Redmine's own MembersController — a single before_action :authorize
 * covers index/show/create/update/destroy alike, there is no separate
 * "view members" permission.
 */
final class MembershipController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Member::class, $project]);

        $members = Member::query()->where('project_id', $project->id)->with('roles')->orderBy('id')->paginate();

        return MembershipResource::collection($members);
    }

    public function show(Member $membership): MembershipResource
    {
        Gate::authorize('view', $membership);

        return new MembershipResource($membership->load('roles'));
    }

    public function store(StoreMembershipRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $member = new Member([
            'project_id' => $project->id,
            'user_id' => $data['user_id'] ?? null,
            'group_id' => $data['group_id'] ?? null,
        ]);
        $member->save();

        $this->syncManagedRoles($member, $project, $data['role_ids'] ?? []);

        return (new MembershipResource($member->load('roles')))->response()->setStatusCode(201);
    }

    public function update(UpdateMembershipRequest $request, Member $membership): MembershipResource
    {
        $data = $request->validated();

        $this->syncManagedRoles($membership, $membership->project, $data['role_ids'] ?? []);

        return new MembershipResource($membership->load('roles'));
    }

    public function destroy(Member $membership): JsonResponse
    {
        Gate::authorize('delete', $membership);

        $managedRoleIds = $this->authorization->managedRolesFor(auth()->user(), $membership->project)->pluck('id');

        if ($membership->roles->pluck('id')->diff($managedRoleIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role_ids' => 'This member holds a role outside the roles you are allowed to manage.',
            ]);
        }

        $membership->delete();

        return response()->json(status: 204);
    }

    /**
     * Roles outside the requester's managed set are left untouched rather
     * than rejected or silently stripped, matching Redmine's
     * Member#set_editable_role_ids. At least one role must remain in the
     * final combined set (untouched + newly submitted-and-managed).
     *
     * @param  array<int>  $submittedRoleIds
     */
    private function syncManagedRoles(Member $member, Project $project, array $submittedRoleIds): void
    {
        $managedRoleIds = $this->authorization->managedRolesFor(auth()->user(), $project)->pluck('id');

        $untouchedRoleIds = $member->roles->pluck('id')->diff($managedRoleIds);
        $touchedRoleIds = collect($submittedRoleIds)->intersect($managedRoleIds);
        $finalRoleIds = $untouchedRoleIds->merge($touchedRoleIds)->unique();

        if ($finalRoleIds->isEmpty()) {
            throw ValidationException::withMessages([
                'role_ids' => 'At least one role is required.',
            ]);
        }

        $member->roles()->sync($finalRoleIds);
    }
}
