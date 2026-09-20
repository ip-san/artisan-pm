<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the language of the request (see SupportedLocales::resolve()) for
 * translated strings and for Carbon's dates.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = SupportedLocales::resolve($request->user(), $request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
