<?php

use App\Models\JournalDetail;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
    app()->setLocale('ja');
});

test('without a setting dates are ISO and times use a 24-hour clock', function () {
    $stored = Carbon::parse('2026-09-24 15:05:00', 'UTC');

    expect(DateTimes::date('2026-09-04'))->toBe('2026-09-04')
        ->and(DateTimes::dateTime($stored))->toBe('2026-09-24 15:05')
        ->and(DateTimes::time($stored))->toBe('15:05');
});

test('each of Redmine\'s date formats is applied, with month names in the display language', function (string $setting, string $english, string $japanese) {
    Setting::set('date_format', $setting);

    app()->setLocale('en');
    expect(DateTimes::date('2026-09-04'))->toBe($english);

    app()->setLocale('ja');
    expect(DateTimes::date('2026-09-04'))->toBe($japanese);
})->with([
    ['%Y-%m-%d', '2026-09-04', '2026-09-04'],
    ['%d/%m/%Y', '04/09/2026', '04/09/2026'],
    ['%d.%m.%Y', '04.09.2026', '04.09.2026'],
    ['%d-%m-%Y', '04-09-2026', '04-09-2026'],
    ['%m/%d/%Y', '09/04/2026', '09/04/2026'],
    ['%d %b %Y', '04 Sep 2026', '04 9月 2026'],
    ['%d %B %Y', '04 September 2026', '04 9月 2026'],
    ['%b %d, %Y', 'Sep 04, 2026', '9月 04, 2026'],
    ['%B %d, %Y', 'September 04, 2026', '9月 04, 2026'],
]);

test('the 12-hour time format is shown with AM/PM in the display language', function () {
    Setting::set('time_format', '%I:%M %p');
    Setting::set('date_format', '%d/%m/%Y');
    $stored = Carbon::parse('2026-09-24 15:05:00', 'UTC');

    app()->setLocale('en');
    expect(DateTimes::dateTime($stored))->toBe('24/09/2026 03:05 PM');

    app()->setLocale('ja');
    expect(DateTimes::time($stored))->toBe('03:05 午後');
});

test('the format and the zone apply together', function () {
    Setting::set('date_format', '%m/%d/%Y');
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);

    expect(DateTimes::dateTime(Carbon::parse('2026-09-24 23:30:00', 'UTC'), $tokyo))->toBe('09/25/2026 08:30');
});

test('an unknown stored format falls back to the default', function () {
    Setting::set('date_format', '%Q');
    Setting::set('time_format', 'nonsense');

    expect(DateTimes::date('2026-09-04'))->toBe('2026-09-04')
        ->and(DateTimes::time(Carbon::parse('2026-09-24 15:05:00')))->toBe('15:05');
});

test('an admin picks the date and time formats from Redmine\'s lists', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-04 15:05:00', 'UTC'));
    $admin = User::factory()->admin()->create();

    $options = DateTimes::dateFormatOptions();
    expect($options[''])->toBe('既定(2026-09-04)')
        ->and($options['%d/%m/%Y'])->toBe('04/09/2026 (dd/mm/yyyy)')
        ->and(DateTimes::timeFormatOptions()['%H:%M'])->toBe('15:05');

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('date_format', '')
        ->assertSeeHtml('data-date-format')
        ->set('date_format', '%d.%m.%Y')
        ->set('time_format', '%I:%M %p')
        ->call('save')->assertHasNoErrors();

    expect(Setting::get('date_format'))->toBe('%d.%m.%Y')
        ->and(Setting::get('time_format'))->toBe('%I:%M %p')
        ->and(DateTimes::date('2026-09-04'))->toBe('04.09.2026');

    Livewire::actingAs($admin)->test('settings.index')
        ->set('date_format', '%Y%m%d')->set('time_format', '%H')
        ->call('save')->assertHasErrors(['date_format', 'time_format']);
});

test('the issue history shows changed dates in the date format', function () {
    Setting::set('date_format', '%d/%m/%Y');
    $detail = new JournalDetail(['property' => 'attr', 'prop_key' => 'due_date', 'old_value' => '2026-09-04', 'new_value' => null]);
    $other = new JournalDetail(['property' => 'attr', 'prop_key' => 'subject', 'old_value' => '2026-09-04']);

    expect($detail->displayValue($detail->old_value))->toBe('04/09/2026')
        ->and($detail->displayValue(null))->toBeNull()
        ->and($other->displayValue($other->old_value))->toBe('2026-09-04');
});
