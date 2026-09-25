<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexUserRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountInformation;
use App\Services\AccountDeletionService;
use App\Support\Api\CustomFieldPayload;
use App\Support\Auth\RandomPassword;
use App\Support\Authorization\AuthorizationService;
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
            // Redmine's Principal.like: name, login, any email address, or
            // first/last name matching every word.
            ->when(isset($data['name']), fn ($query) => $query->matchingName((string) $data['name']))
            // Redmine's users_controller#index: ?group_id= shortcuts the
            // UserQuery "member of group" filter (UserGroupFilter).
            ->when($data['group_id'] ?? null, fn ($query, $groupId) => $query->whereHas('groups', fn ($groups) => $groups->whereKey($groupId)))
            ->orderBy('name')
            ->paginate();

        return UserResource::collection($users);
    }

    /**
     * GET /users/current — Redmine's users/current: the caller's own account.
     */
    public function current(Request $request): UserResource
    {
        $this->loadIncludes($request, $request->user());

        return new UserResource($request->user());
    }

    public function show(Request $request, User $user): UserResource
    {
        abort_unless($user->isVisibleTo($request->user()), 404);

        $this->loadIncludes($request, $user);

        return new UserResource($user);
    }

    /**
     * Redmine's users/show.api.rsb ?include=groups,memberships: groups is
     * eager loaded unconditionally (UserResource itself gates it to an
     * admin or the user's own request); memberships are narrowed here to
     * projects the viewer may see, like Redmine's
     * `@user.memberships.where(Project.visible_condition(User.current))`.
     */
    private function loadIncludes(Request $request, User $user): void
    {
        $requested = collect(explode(',', (string) $request->query('include', '')))->map(fn (string $key) => trim($key))->filter();

        if ($requested->contains('groups')) {
            $user->load('groups');
        }

        if ($requested->contains('memberships')) {
            $visibleProjectIds = app(AuthorizationService::class)->visibleProjectIds($request->user(), 'view_project');
            $user->load(['memberships' => fn ($query) => $query->whereIn('project_id', $visibleProjectIds)->with(['project', 'roles'])]);
        }
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $authSourceId = $data['auth_source_id'] ?? null;

        // Redmine's generate_password: a random password for a local account.
        $plainPassword = $authSourceId === null
            ? (($data['generate_password'] ?? false) ? RandomPassword::generate() : ($data['password'] ?? null))
            : null;

        $user = new User([
            'login' => $data['login'],
            ...User::normalizeNameInput(array_intersect_key($data, array_flip(['name', 'firstname', 'lastname']))),
            'email' => $data['email'],
            // A directory-backed account never uses a local password; an
            // unguessable placeholder satisfies the column, like the admin form.
            'password' => Hash::make($plainPassword ?? Str::random(40)),
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

        // Redmine's send_information: mail the new account its login (and
        // password).
        if ($data['send_information'] ?? false) {
            $user->notify(new AccountInformation($plainPassword));
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $attributes = User::normalizeNameInput(collect($data)->only(['login', 'name', 'firstname', 'lastname', 'email', 'language', 'status', 'mail_notification', 'no_self_notified'])->all());

        if (array_key_exists('auth_source_id', $data)) {
            $attributes['auth_source_id'] = $data['auth_source_id'];
        }

        $isLocal = ($attributes['auth_source_id'] ?? $user->auth_source_id) === null;
        $plainPassword = null;

        if ($isLocal && ($data['generate_password'] ?? false)) {
            $plainPassword = RandomPassword::generate();
        } elseif ($isLocal && ! empty($data['password'])) {
            $plainPassword = $data['password'];
        }

        if ($plainPassword !== null) {
            $attributes['password'] = Hash::make($plainPassword);
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

        // Redmine's update sends it to an active user other than the caller.
        if (($data['send_information'] ?? false) && $user->isActive() && ! $user->is($request->user())) {
            $user->notify(new AccountInformation($plainPassword));
        }

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
