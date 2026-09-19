<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Enums\UserStatus;
use App\Http\Requests\Api\V1\IndexUserRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Support\Api\CustomFieldPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Listing, creating, updating and deleting users is administrators only
 * (UserPolicy denies everyone else; the Gate::before admin bypass grants it).
 * Reading one user follows Redmine: anyone may fetch a user they can see
 * (User::isVisibleTo — the public profile rule), with the fields the viewer is
 * entitled to — see UserResource.
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

    public function show(Request $request, User $user): UserResource
    {
        abort_unless($user->isVisibleTo($request->user()), 404);

        return new UserResource($user);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $authSourceId = $data['auth_source_id'] ?? null;

        $user = new User([
            'login' => $data['login'],
            'name' => $data['name'],
            'email' => $data['email'],
            // A directory-backed account never uses a local password; an
            // unguessable placeholder satisfies the column, like the admin form.
            'password' => $authSourceId === null ? Hash::make($data['password']) : Hash::make(Str::random(40)),
            'auth_source_id' => $authSourceId,
            'language' => $data['language'] ?? null,
            'status' => $data['status'] ?? UserStatus::Active->value,
            'no_self_notified' => $data['no_self_notified'] ?? Setting::get('default_users_no_self_notified', true),
            ...isset($data['mail_notification']) ? ['mail_notification' => $data['mail_notification']] : [],
        ]);
        // is_admin is not mass-assignable — see User's docblock.
        $user->is_admin = (bool) ($data['is_admin'] ?? false);
        $user->must_change_passwd = $authSourceId === null && ($data['must_change_passwd'] ?? false);
        $customFieldData = CustomFieldPayload::extract($request, $user->relevantCustomFields(), $request->user(), requireAll: true);
        $user->save();
        $user->setCustomFieldValues($customFieldData);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $attributes = collect($data)->only(['login', 'name', 'email', 'language', 'status', 'mail_notification', 'no_self_notified'])->all();

        if (array_key_exists('auth_source_id', $data)) {
            $attributes['auth_source_id'] = $data['auth_source_id'];
        }

        if (! empty($data['password']) && ($attributes['auth_source_id'] ?? $user->auth_source_id) === null) {
            $attributes['password'] = Hash::make($data['password']);
        }

        $user->fill($attributes);

        if (array_key_exists('is_admin', $data)) {
            $user->is_admin = (bool) $data['is_admin'];
        }

        if (array_key_exists('must_change_passwd', $data)) {
            $user->must_change_passwd = $user->auth_source_id === null && $data['must_change_passwd'];
        }

        $customFieldData = CustomFieldPayload::extract($request, $user->relevantCustomFields(), $request->user());
        $user->save();
        $user->setCustomFieldValues($customFieldData);

        return new UserResource($user);
    }

    /**
     * Deletes (anonymizes) the account. An administrator cannot delete
     * themselves, nor the last active administrator.
     */
    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        abort_if($user->is($request->user()), 403, 'You cannot delete your own account here.');

        $isLastAdmin = $user->is_admin && ! User::query()->where('is_admin', true)->where('status', UserStatus::Active)->whereKeyNot($user->id)->exists();

        abort_if($isLastAdmin, 422, 'The last active administrator cannot be deleted.');

        app(AccountDeletionService::class)->delete($user);

        return response()->noContent();
    }
}
