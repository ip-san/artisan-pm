<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
     * STATUS_REGISTERED), and 'automatic' activates it immediately — the
     * app's prior, only behavior. Redmine's third mode, email-confirmation
     * activation, is intentionally not implemented: this app has no
     * outbound notification email system yet.
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
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'status' => $mode === 'manual' ? UserStatus::Registered->value : UserStatus::Active->value,
        ]);
    }
}
