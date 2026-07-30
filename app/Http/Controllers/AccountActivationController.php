<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Matches Redmine's AccountController#activate: consumes the signed
 * activation link sent by ConfirmAccountRegistration (this app's
 * equivalent of Redmine's Token model for the 'register' action —
 * Laravel's built-in signed-URL verification takes the place of a
 * separate tokens table). The 'signed' route middleware has already
 * rejected an expired or tampered URL before this ever runs.
 */
final class AccountActivationController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        abort_unless($user->status === UserStatus::Registered, 404);

        $user->status = UserStatus::Active;
        $user->save();

        return redirect()->route('login')->with('status', 'アカウントを有効化しました。ログインしてください。');
    }
}
