<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's ApplicationController#check_twofa_activation, which
 * redirects an authenticated user to the account page (with a warning) on
 * every request until they set up two-factor authentication, once
 * User#must_activate_twofa? is true. Applied after 'auth' in
 * routes/web.php, so $request always has an authenticated user by the
 * time this runs. The profile page is exempted so the user can actually
 * reach the 2FA setup form; Fortify's own two-factor-* routes (enable,
 * confirm, QR code, recovery codes) live outside this app's route group
 * entirely and are unaffected.
 */
final class EnforceTwofaRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->routeIs('profile.index') === false && $user->mustActivateTwoFactor()) {
            return redirect()->route('profile.index')
                ->with('status', '管理者の設定により、二要素認証の設定が必須になっています。設定を完了してください。');
        }

        return $next($request);
    }
}
