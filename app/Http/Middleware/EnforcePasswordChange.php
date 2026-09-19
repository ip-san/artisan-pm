<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redmine's ApplicationController#check_password_change: a signed-in user
 * whose password has expired (password_max_age) or who was told to change it
 * at next login is sent to the account page on every request until they do.
 * The profile page stays reachable so the change can be made; signing out
 * (Fortify's route) sits outside this group.
 */
final class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->routeIs('profile.index') === false && $user->mustChangePassword()) {
            return redirect()->route('profile.index')
                ->with('status', 'パスワードを変更してください。変更するまで他のページは利用できません。');
        }

        return $next($request);
    }
}
