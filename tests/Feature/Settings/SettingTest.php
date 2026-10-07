<?php

use App\Models\Setting;
use App\Support\Attachments\AttachmentValidationRules;
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

test('reading many different settings costs one query, not one per key', function () {
    foreach (range(1, 20) as $i) {
        Setting::set("key_{$i}", $i);
    }

    $settingsQueries = 0;
    DB::listen(function ($query) use (&$settingsQueries) {
        if (str_contains($query->sql, '"settings"')) {
            $settingsQueries++;
        }
    });

    foreach (range(1, 20) as $i) {
        expect(Setting::get("key_{$i}"))->toBe($i);
    }
    expect(Setting::get('never_set', 'fallback'))->toBe('fallback')
        ->and($settingsQueries)->toBe(1);
});

test('each caller gets its own default for an unset key, whoever asked first (A17-10)', function () {
    expect(Setting::get('incoming_mail_default_tracker_id', 0))->toBe(0)
        ->and(Setting::get('incoming_mail_default_tracker_id'))->toBeNull()
        ->and(Setting::get('incoming_mail_default_tracker_id', 7))->toBe(7);
});

test('attachments default to Redmine\'s 5120 KB, never above the upload ceiling (A17-11)', function () {
    expect(AttachmentValidationRules::maxSizeInKb())->toBe(5120);

    Setting::set('attachment_max_size', 999999);

    expect(AttachmentValidationRules::maxSizeInKb())->toBe(intdiv((int) config('media-library.max_file_size'), 1024));
});
