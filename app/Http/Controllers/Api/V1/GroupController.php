<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGroupRequest;
use App\Http\Requests\Api\V1\UpdateGroupRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Groups are a site-wide admin resource — every action is gated by
 * GroupPolicy, which denies everyone except the Gate::before admin
 * bypass, matching Redmine's own require_admin on every Groups action
 * except show (this app has no unauthenticated/non-admin read path for
 * groups at all, so index is gated the same as the rest, unlike
 * TrackerController's deliberately open index).
 */
final class GroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Group::class);

        $groups = Group::query()->with('users')->orderBy('name')->get();

        return GroupResource::collection($groups);
    }

    public function show(Group $group): GroupResource
    {
        Gate::authorize('view', $group);

        return new GroupResource($group->load('users'));
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userIds = $data['user_ids'] ?? [];
        unset($data['user_ids']);

        $group = new Group($data);
        $group->save();
        $group->users()->sync($userIds);

        return (new GroupResource($group->load('users')))->response()->setStatusCode(201);
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $data = $request->validated();

        if (array_key_exists('user_ids', $data)) {
            $group->users()->sync($data['user_ids']);
            unset($data['user_ids']);
        }

        $group->update($data);

        return new GroupResource($group->load('users'));
    }

    public function destroy(Group $group): JsonResponse
    {
        Gate::authorize('delete', $group);

        $group->delete();

        return response()->json(status: 204);
    }
}
