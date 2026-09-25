<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's Setting.autologin (account/login.html.erb only shows
 * the "stay logged in" checkbox, and only honors it in
 * AccountController#successful_authentication, when the setting is on;
 * default off). This app's login view (resources/views/auth/login.blade.php)
 * already hides the checkbox based on the same setting, but a POST can
 * still be crafted with remember=1 directly — Fortify's own login
 * pipeline (Laravel\Fortify\Actions\AttemptToAuthenticate) reads
 * $request->boolean('remember') itself with no hook to intercept, so the
 * defense-in-depth here is to strip the input before that pipeline ever
 * sees it, applied via config/fortify.php's global middleware stack.
 *
 * Redmine's autologin setting is itself an integer number of retention
 * days (0/1/7/30/365, config/settings.yml), not a plain on/off flag:
 * Token.find_active_user('autologin', key, Setting.autologin.to_i)
 * rejects an autologin token once it is older than that many days
 * (app/models/token.rb#find_token). Laravel's remember-me cookie has no
 * separate server-side token-age check — the closest equivalent is the
 * cookie's own expiry, via SessionGuard#setRememberDuration (minutes).
 * That must be set on the guard instance actually used to queue the
 * recaller cookie, which happens either here at login.store (no 2FA) or
 * at two-factor.login.store (2FA — Fortify stashes "remember" in the
 * session and completes Auth::login with it there instead), so both
 * routes are covered.
 */
final class EnforceAutologinSetting
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login.store', 'two-factor.login.store')) {
            $days = (int) Setting::get('autologin', 0);

            if ($days <= 0) {
                $request->request->remove('remember');
            } else {
                $guard = Auth::guard('web');

                if ($guard instanceof SessionGuard) {
                    $guard->setRememberDuration($days * 1440);
                }
            }
        }

        return $next($request);
    }
}
