<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redmine's `jsonp_enabled`: with the setting on, a GET to the REST API
 * carrying `callback` (or `jsonp`) gets its JSON wrapped as `callback(...)`
 * and served as application/javascript. The callback keeps only letters,
 * digits, `_` and `.`, and a name that empties out is ignored. Off by
 * default: JSONP lets any page read the response of a request its visitor
 * authenticates with a key in the URL, so it is opt-in and GET-only here.
 */
final class WrapJsonpResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || ! Setting::get('jsonp_enabled', false) || ! $response instanceof JsonResponse) {
            return $response;
        }

        $callback = preg_replace('/[^a-zA-Z0-9_.]/', '', (string) ($request->query('callback') ?? $request->query('jsonp') ?? ''));

        if ($callback === '' || $callback === null || ! $response->isSuccessful()) {
            return $response;
        }

        $response->setContent($callback.'('.$response->getContent().')');
        $response->headers->set('Content-Type', 'application/javascript');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
