<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's Setting.login_required (ApplicationController
 * #check_if_login_required: `require_login if Setting.login_required?`,
 * default on) — applied only to the handful of routes this app makes
 * conditionally guest-reachable (issue list/detail, wiki pages,
 * attachments), unlike the rest of the app's routes, which stay behind
 * the blanket 'auth' middleware regardless of this setting. With the
 * setting on (the default, and the only state possible before this
 * setting existed), a guest hitting one of these routes is redirected to
 * login exactly as 'auth' would do. With it off, the request proceeds
 * unauthenticated and each Volt component's own policy check
 * (ProjectPolicy/IssuePolicy/WikiPagePolicy, all already `?User $user`
 * aware) decides whether the specific project/model is visible to a
 * guest — this middleware only ever gates "must a session exist at all,"
 * never project visibility itself.
 */
final class EnforceLoginRequiredSetting
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && Setting::get('login_required', true)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
