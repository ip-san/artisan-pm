<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use App\Support\Api\CustomFieldPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The fields the viewer is entitled to, as in Redmine's show.api.rsb:
 * everyone gets the public ones (the email address unless the user hides it);
 * administrators and the user themselves also see the admin flag, language and
 * notification settings; only administrators see status and the authentication
 * source; only the user sees their own API key. `name` is this app's stored
 * name; `firstname`/`lastname` are Redmine's (null when not entered). Custom
 * field values are omitted, like every other API resource here.
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
        $viewer = $request->user();
        $isAdmin = (bool) $viewer?->is_admin;
        $isSelf = $viewer?->is($user) ?? false;

        return [
            'id' => $user->id,
            'login' => $user->login,
            'name' => $user->name,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            // Redmine's users/show hides a hide_mail address from everyone but
            // administrators; /my/account always shows the user their own.
            ...($isAdmin || $isSelf || ! $user->preference('hide_mail') ? ['email' => $user->email] : []),
            ...($isAdmin || $isSelf ? [
                'is_admin' => $user->is_admin,
                'language' => $user->language,
                'mail_notification' => $user->mail_notification->value,
                'no_self_notified' => $user->no_self_notified,
            ] : []),
            ...($isAdmin ? [
                'status' => $user->status->value,
                'auth_source_id' => $user->auth_source_id,
                'must_change_passwd' => $user->must_change_passwd,
                'passwd_changed_on' => $user->passwd_changed_on?->toIso8601String(),
            ] : []),
            ...($isAdmin || $isSelf ? ['custom_fields' => CustomFieldPayload::read($user)] : []),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // A user's own key only — Redmine also shows it to administrators,
            // but there is no reason to hand every key to any admin token.
            ...($isSelf ? ['api_key' => $user->api_key] : []),
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
            // Present only when the controller loaded them for
            // ?include=groups,memberships (Redmine's users/show.api.rsb) —
            // groups admin/self only, like the rest of this resource.
            'groups' => $this->when(
                $user->relationLoaded('groups') && ($isAdmin || $isSelf),
                fn () => $user->groups->map(fn (Group $group) => ['id' => $group->id, 'name' => $group->name])->values()->all(),
            ),
            'memberships' => $this->when(
                $user->relationLoaded('memberships'),
                fn () => $user->memberships->map(fn (Member $member) => [
                    'id' => $member->id,
                    'project' => ['id' => $member->project->id, 'name' => $member->project->name],
                    'roles' => $member->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all(),
                ])->values()->all(),
            ),
        ];
    }
}
