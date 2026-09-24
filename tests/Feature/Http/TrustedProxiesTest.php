<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Shared hosting often terminates HTTPS at a front proxy: TRUSTED_PROXIES
 * (app.trusted_proxies) lets the app believe its X-Forwarded-* headers.
 */
beforeEach(function () {
    Route::get('/_trusted-proxies-probe', fn (Request $request) => response()->json([
        'secure' => $request->isSecure(),
        'url' => url('/projects'),
    ]));
});

function proxiedProbe(): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'pm.example.jp', 'X-Forwarded-Port' => '443'])
        ->getJson('/_trusted-proxies-probe')
        ->assertOk()
        ->json();
}

test('forwarded headers are ignored when no proxy is trusted', function () {
    config(['app.trusted_proxies' => null]);

    expect(proxiedProbe()['secure'])->toBeFalse();
});

test('a wildcard trusts any proxy, so the request is https', function () {
    config(['app.trusted_proxies' => '*']);

    expect(proxiedProbe())->toBe(['secure' => true, 'url' => 'https://pm.example.jp/projects']);
});

test('a listed proxy address or range is trusted', function () {
    config(['app.trusted_proxies' => '192.0.2.1, 10.0.0.0/8']);

    expect(proxiedProbe()['secure'])->toBeTrue();
});

test('a proxy that is not listed is not trusted', function () {
    config(['app.trusted_proxies' => '192.0.2.1']);

    expect(proxiedProbe()['secure'])->toBeFalse();
});
