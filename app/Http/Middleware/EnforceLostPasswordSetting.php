<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's Setting.lost_password (AccountController#lost_password
 * redirects home unless it is on; default on). Only the self-service
 * request endpoints are closed when it is off: a reset link already in
 * someone's hands can only have come from an administrator's "send reset
 * email" action, which is not the feature this setting governs, so
 * password.reset / password.update keep working. Applied through
 * config/fortify.php's global middleware stack like EnforceAutologinSetting.
 */
final class EnforceLostPasswordSetting
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('password.request', 'password.email') && ! Setting::get('lost_password', true)) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
