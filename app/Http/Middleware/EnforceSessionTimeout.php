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
 * always has an authenticated user by the time this runs. Redmine also
 * has a separate session_lifetime setting (an absolute cap from login,
 * regardless of activity) — not implemented here, a documented scope cut
 * since it's the same mechanism against a different reference timestamp
 * and idle timeout is the gap this specifically closes.
 */
final class EnforceSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeoutMinutes = (int) Setting::get('session_timeout', 0);

        if ($timeoutMinutes > 0) {
            $lastActivity = $request->session()->get('last_activity_at');

            if ($lastActivity !== null && now()->diffInMinutes(Carbon::createFromTimestamp($lastActivity), absolute: true) > $timeoutMinutes) {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'セッションがタイムアウトしました。再度ログインしてください。');
            }
        }

        $request->session()->put('last_activity_at', now()->timestamp);

        return $next($request);
    }
}
