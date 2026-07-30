<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's own RegisteredUserController unconditionally calls
 * $guard->login($user) right after CreatesNewUsers::create() returns,
 * regardless of the created user's status — so a 'manual'-approval or
 * 'email'-confirmation self-registration (both create the account as
 * UserStatus::Registered, locked pending activation) was silently getting
 * a live authenticated session for the remainder of that one request
 * cycle, defeating the entire point of "pending approval"/"unconfirmed"
 * for that session. AuthenticateUser's isActive() gate only runs on a
 * subsequent, separate login attempt — it never ran here, since this path
 * bypasses Fortify::authenticateUsing() entirely. Overriding this response
 * closes that gap for both modes.
 */
final class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = Setting::get('self_registration', 'automatic') === 'email'
                ? '確認メールを送信しました。メール内のリンクからアカウントを有効化してください。'
                : '登録を受け付けました。管理者の承認をお待ちください。';

            return $request->wantsJson()
                ? new JsonResponse('', 201)
                : redirect()->route('login')->with('status', $message);
        }

        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->intended(Fortify::redirects('register'));
    }
}
