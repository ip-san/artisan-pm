<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only, admin-only (GroupController's pattern: UserPolicy denies
 * every ability, access comes entirely from the Gate::before admin
 * bypass). Redmine本家はindexのみ管理者限定でshowは`@user.visible?`
 * (本人/公開プロフィール設定次第で誰でも閲覧可)だが、本アプリの
 * UserPolicyはindex/showともに管理者限定のため、本家のような
 * 管理者/本人/一般での応答フィールドの出し分けは存在しない
 * (UserResourceは常に単一の形状)。
 */
final class UserController extends Controller
{
    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $users = User::query()
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['name']), function ($query) use ($data) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$data['name']}%")->orWhere('email', 'like', "%{$data['name']}%"));
            })
            ->orderBy('name')
            ->paginate();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user);
    }
}
