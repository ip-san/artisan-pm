<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Api\CustomFieldPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGroupRequest;
use App\Http\Requests\Api\V1\UpdateGroupRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
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

        $customFieldData = CustomFieldPayload::extract($request, (new Group)->relevantCustomFields(), $request->user(), requireAll: true);

        $group = new Group($data);
        $group->save();
        $group->users()->sync($userIds);
        $group->setCustomFieldValues($customFieldData);

        return (new GroupResource($group->load('users')))->response()->setStatusCode(201);
    }

    /**
     * POST /groups/{group}/users — Redmine's add_users: `user_id` or
     * `user_ids` name users to add; ones already in the group or unknown are
     * skipped, and if none could be added the answer is 422 rather than a
     * silent success.
     */
    public function addUsers(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('update', $group);

        $userIds = $this->requestedUserIds($request);

        $newUserIds = User::query()
            ->whereIn('id', $userIds)
            ->whereDoesntHave('groups', fn ($query) => $query->whereKey($group->id))
            ->pluck('id');

        if ($newUserIds->isEmpty()) {
            return response()->json([
                'message' => 'No user could be added to the group.',
                'errors' => ['user_id' => ['The given users do not exist or are already in the group.']],
            ], 422);
        }

        $group->users()->attach($newUserIds->all());

        return response()->json(status: 204);
    }

    /**
     * DELETE /groups/{group}/users — Redmine's remove_users: `user_id` or
     * `user_ids` name members to remove; if none of them is in the group
     * the answer is 404.
     */
    public function removeUsers(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('update', $group);

        $memberIds = $group->users()->whereIn('users.id', $this->requestedUserIds($request))->pluck('users.id');

        abort_if($memberIds->isEmpty(), 404);

        $group->users()->detach($memberIds->all());

        return response()->json(status: 204);
    }

    /**
     * DELETE /groups/{group}/users/{user} — the classic form Redmine still
     * routes (deprecated there in favour of remove_users).
     */
    public function removeUser(Group $group, User $user): JsonResponse
    {
        Gate::authorize('update', $group);

        abort_unless($group->users()->whereKey($user->id)->exists(), 404);

        $group->users()->detach($user->id);

        return response()->json(status: 204);
    }

    /**
     * @return array<int, int>
     */
    private function requestedUserIds(Request $request): array
    {
        $raw = [...Arr::wrap($request->input('user_ids')), ...Arr::wrap($request->input('user_id'))];

        return collect($raw)
            ->filter(fn ($value) => (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $data = $request->validated();

        if (array_key_exists('user_ids', $data)) {
            $group->users()->sync($data['user_ids']);
            unset($data['user_ids']);
        }

        $customFieldData = CustomFieldPayload::extract($request, $group->relevantCustomFields(), $request->user());

        $group->update($data);
        $group->setCustomFieldValues($customFieldData);

        return new GroupResource($group->load('users'));
    }

    public function destroy(Group $group): JsonResponse
    {
        Gate::authorize('delete', $group);

        $group->delete();

        return response()->json(status: 204);
    }
}
