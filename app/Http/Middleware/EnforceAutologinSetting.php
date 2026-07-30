<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
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
 * sees it, applied via config/fortify.php's global middleware stack
 * (scoped to the login route only, to avoid touching unrelated Fortify
 * requests like register/password-reset that don't have a remember field).
 */
final class EnforceAutologinSetting
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login.store') && ! Setting::get('autologin', false)) {
            $request->request->remove('remember');
        }

        return $next($request);
    }
}
