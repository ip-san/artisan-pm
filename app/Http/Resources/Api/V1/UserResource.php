<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The fields the viewer is entitled to, as in Redmine's show.api.rsb:
 * everyone gets the public ones (the email address unless the user hides it);
 * administrators and the user themselves also see the admin flag, language and
 * notification settings; only administrators see status and the authentication
 * source; only the user sees their own API key. `name` is a
 * single combined field — this app has no firstname/lastname split. Custom
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
            ...($isAdmin || ! $user->preference('hide_mail') ? ['email' => $user->email] : []),
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
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // A user's own key only — Redmine also shows it to administrators,
            // but there is no reason to hand every key to any admin token.
            ...($isSelf ? ['api_key' => $user->api_key] : []),
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ];
    }
}
