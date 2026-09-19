<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redmine's SysController#check_enabled: the repository management web
 * service answers only when `sys_api_enabled` is on and the request carries
 * the configured `sys_api_key` (query or body `key`). The comparison is
 * constant-time, and an empty configured key never matches.
 */
final class EnforceSysApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) Setting::get('sys_api_key', '');
        $given = (string) $request->input('key', '');

        if (! Setting::get('sys_api_enabled', false) || $configured === '' || ! hash_equals($configured, $given)) {
            return response('Access denied. Repository management WS is disabled or key is invalid.', 403)
                ->header('Content-Type', 'text/plain');
        }

        return $next($request);
    }
}
