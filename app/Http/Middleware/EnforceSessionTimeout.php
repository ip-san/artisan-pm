<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's Setting.session_timeout (an idle-timeout in minutes,
 * enforced by User.verify_session_token on every request): once a session
 * has gone this long without activity, it's invalidated and the user is
 * sent back to login. 0 (Redmine's own "無効" option) disables this.
 *
 * Applied after the 'auth' middleware in routes/web.php, so $request
 * always has an authenticated user by the time this runs. Redmine's
 * session_lifetime — an absolute cap from when the session began,
 * regardless of activity — is the same check against `session_started_at`.
 * That stamp is first set on the first authenticated request of a session
 * and flushed with the session on logout, so a session that predates the
 * setting starts counting the first time it is seen afterwards.
 */
final class EnforceSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeoutMinutes = (int) Setting::get('session_timeout', 0);
        $lifetimeMinutes = (int) Setting::get('session_lifetime', 0);

        $lastActivity = $request->session()->get('last_activity_at');
        $startedAt = $request->session()->get('session_started_at');

        $idleTooLong = $timeoutMinutes > 0
            && $lastActivity !== null
            && now()->diffInMinutes(Carbon::createFromTimestamp($lastActivity), absolute: true) > $timeoutMinutes;

        $livedTooLong = $lifetimeMinutes > 0
            && $startedAt !== null
            && now()->diffInMinutes(Carbon::createFromTimestamp($startedAt), absolute: true) > $lifetimeMinutes;

        if ($idleTooLong || $livedTooLong) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'セッションがタイムアウトしました。再度ログインしてください。');
        }

        $request->session()->put('last_activity_at', now()->timestamp);

        if ($startedAt === null) {
            $request->session()->put('session_started_at', now()->timestamp);
        }

        return $next($request);
    }
}
