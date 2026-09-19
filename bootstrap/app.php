<?php

use App\Http\Middleware\EnforceLoginRequiredSetting;
use App\Http\Middleware\EnforceRestApiEnabledSetting;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnforceTwofaRequired;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered explicitly in MailNotificationServiceProvider and
    // WebhookServiceProvider. Laravel's auto-discovery (which does resolve a
    // union-typed handle() to one registration per event) would register the
    // same listeners a second time, so every mail and webhook went out twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->alias([
            'session.timeout' => EnforceSessionTimeout::class,
            'rest-api.enabled' => EnforceRestApiEnabledSetting::class,
            'twofa.required' => EnforceTwofaRequired::class,
            'login.required' => EnforceLoginRequiredSetting::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
