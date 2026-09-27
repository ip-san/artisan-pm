<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

test('an unset key returns the given default', function () {
    expect(Setting::get('nonexistent', 'fallback'))->toBe('fallback');
});

test('a set value round-trips through get', function () {
    Setting::set('app_title', 'Custom Title');

    expect(Setting::get('app_title'))->toBe('Custom Title');
});

test('repeated reads of a setting within a request do not go back to the cache store', function () {
    config(['cache.default' => 'database']);
    Setting::set('app_title', 'Tracker');
    Setting::get('app_title');

    $cacheQueries = 0;
    DB::listen(function ($query) use (&$cacheQueries) {
        if (str_contains($query->sql, '"cache"')) {
            $cacheQueries++;
        }
    });

    foreach (range(1, 5) as $ignored) {
        expect(Setting::get('app_title'))->toBe('Tracker');
    }

    expect($cacheQueries)->toBeLessThanOrEqual(1);
});

test('a cached read reflects a subsequent write', function () {
    expect(Setting::get('default_issues_per_page', 25))->toBe(25);

    Setting::set('default_issues_per_page', 50);

    expect(Setting::get('default_issues_per_page', 25))->toBe(50);
});
