<?php

use App\Models\Setting;

test('an unset key returns the given default', function () {
    expect(Setting::get('nonexistent', 'fallback'))->toBe('fallback');
});

test('a set value round-trips through get', function () {
    Setting::set('app_title', 'Custom Title');

    expect(Setting::get('app_title'))->toBe('Custom Title');
});

test('a cached read reflects a subsequent write', function () {
    expect(Setting::get('default_issues_per_page', 25))->toBe(25);

    Setting::set('default_issues_per_page', 50);

    expect(Setting::get('default_issues_per_page', 25))->toBe(50);
});
