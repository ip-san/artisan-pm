<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        // A token can outlive the account state it was issued in: a locked
        // user or one that now authenticates against an external directory
        // (LDAP) must not be able to set a local password with it.
        if (! $user->isActive() || $user->auth_source_id !== null) {
            throw ValidationException::withMessages(['email' => 'このアカウントのパスワードは再設定できません。']);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'must_change_passwd' => false,
        ])->save();
    }
}
