<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use App\Notifications\ConfirmAccountRegistration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Matches Redmine's Setting.self_registration: 'disabled' rejects the
     * submission outright (mirroring the account/register page's own
     * redirect-away-if-disabled check, as defense in depth against a
     * direct POST bypassing that), 'manual' creates the account locked
     * pending admin approval (UserStatus::Registered, matching Redmine's
     * STATUS_REGISTERED), 'email' creates it the same way but sends a
     * signed activation link instead of waiting on an admin (Redmine's
     * '1'/register_by_email_activation), and 'automatic' activates it
     * immediately — the app's original, only behavior.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $mode = Setting::get('self_registration', 'automatic');

        if ($mode === 'disabled') {
            throw ValidationException::withMessages([
                'email' => 'このサイトではアカウント登録を受け付けていません。',
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:'.User::LOGIN_LENGTH_LIMIT, 'regex:'.User::LOGIN_FORMAT_REGEX, new UniqueUserValueIgnoringCase('login')],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueUserValueIgnoringCase('email'),
                new AllowedEmailDomain,
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'login' => $input['login'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'status' => in_array($mode, ['manual', 'email'], true) ? UserStatus::Registered->value : UserStatus::Active->value,
            // Matches UserPreference#set_editable_attribute seeding
            // no_self_notified from Setting.default_users_no_self_notified
            // whenever it isn't explicitly given at creation time.
            'no_self_notified' => Setting::get('default_users_no_self_notified', true),
        ]);

        if ($mode === 'email') {
            // 1 day, matching Redmine's Token.validity_time default for
            // the 'register' action (config/redmine.rb has no override).
            $url = URL::temporarySignedRoute('account.activate', now()->addDay(), ['user' => $user->id]);
            $user->notify(new ConfirmAccountRegistration($url));
        }

        return $user;
    }
}
