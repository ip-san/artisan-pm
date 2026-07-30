<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's Setting.rest_api_enabled (default off,
 * config/settings.yml): ApplicationController#find_current_user only
 * attempts API-key/OAuth resolution when this is on, and
 * #require_login's format.api branch returns :forbidden outright when
 * it's off. This app has no separate "already authenticated via the web
 * session" path into routes/api.php (unlike Redmine, where an HTML
 * session cookie can still drive some API-embedded requests), so the
 * simpler and equally faithful equivalent is to reject every API
 * request up front — before Passport/api-key resolution even runs —
 * when the setting is disabled.
 */
final class EnforceRestApiEnabledSetting
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Setting::get('rest_api_enabled', false), 403);

        return $next($request);
    }
}
