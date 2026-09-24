<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;
use App\Support\Locale\TimeZones;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(fn () => Carbon::setTestNow());

test('a user sees their own zone, else the default setting, else the server zone', function () {
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);
    $unset = User::factory()->create(['time_zone' => null]);
    $unknown = User::factory()->create(['time_zone' => 'Mars/Olympus']);

    expect(TimeZones::forUser($tokyo))->toBe('Asia/Tokyo')
        ->and(TimeZones::forUser($unset))->toBe('UTC')
        ->and(TimeZones::forUser(null))->toBe('UTC');

    Setting::set('default_users_time_zone', 'America/New_York');

    expect(TimeZones::forUser($tokyo))->toBe('Asia/Tokyo')
        ->and(TimeZones::forUser($unset))->toBe('America/New_York')
        ->and(TimeZones::forUser($unknown))->toBe('America/New_York')
        ->and(TimeZones::forUser(null))->toBe('America/New_York');
});

test('stored times are shown in the viewer zone, across midnight', function () {
    $stored = Carbon::parse('2026-09-24 23:30:00', 'UTC');
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);
    $losAngeles = User::factory()->create(['time_zone' => 'America/Los_Angeles']);
    $utc = User::factory()->create();

    expect(DateTimes::dateTime($stored, $tokyo))->toBe('2026-09-25 08:30')
        ->and(DateTimes::dateOf($stored, $tokyo))->toBe('2026-09-25')
        ->and(DateTimes::time($stored, $tokyo))->toBe('08:30')
        ->and(DateTimes::dateTime($stored, $losAngeles))->toBe('2026-09-24 16:30')
        ->and(DateTimes::dateTime($stored, $utc))->toBe('2026-09-24 23:30')
        ->and(DateTimes::dateTime(null, $tokyo))->toBeNull();

    // The attribute itself is left in UTC, so saving the model keeps it.
    expect($stored->timezoneName)->toBe('UTC')->and($stored->format('H:i'))->toBe('23:30');
});

test('the signed-in user is the viewer unless a mail is rendered for someone else', function () {
    $stored = Carbon::parse('2026-09-24 23:30:00', 'UTC');
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);
    $newYork = User::factory()->create(['time_zone' => 'America/New_York']);

    expect(DateTimes::dateTime($stored))->toBe('2026-09-24 23:30');

    $this->actingAs($tokyo);
    expect(DateTimes::dateTime($stored))->toBe('2026-09-25 08:30')
        ->and(DateTimes::asViewer($newYork, fn () => DateTimes::dateTime($stored)))->toBe('2026-09-24 19:30')
        ->and(DateTimes::dateTime($stored))->toBe('2026-09-25 08:30');
});

test('date-only values are never shifted', function () {
    $this->actingAs(User::factory()->create(['time_zone' => 'Pacific/Kiritimati']));

    expect(DateTimes::date(Carbon::parse('2026-09-24')))->toBe('2026-09-24')
        ->and(DateTimes::date('2026-09-24'))->toBe('2026-09-24')
        ->and(DateTimes::date(null))->toBeNull()
        ->and(DateTimes::date(''))->toBeNull();

    $this->actingAs(User::factory()->create(['time_zone' => 'Pacific/Pago_Pago']));
    expect(DateTimes::date(Carbon::parse('2026-09-24')))->toBe('2026-09-24');
});

test('today is the current day where the viewer is', function () {
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);
    $losAngeles = User::factory()->create(['time_zone' => 'America/Los_Angeles']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    expect(DateTimes::today($tokyo)->toDateString())->toBe('2026-09-25')
        ->and(DateTimes::today($losAngeles)->toDateString())->toBe('2026-09-24')
        ->and(DateTimes::today()->toDateString())->toBe('2026-09-24');

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC'));
    expect(DateTimes::today($tokyo)->toDateString())->toBe('2026-09-24')
        ->and(DateTimes::today($losAngeles)->toDateString())->toBe('2026-09-23');
});

test('a day in the viewer zone spans the matching UTC moments', function () {
    $tokyo = User::factory()->create(['time_zone' => 'Asia/Tokyo']);

    [$start, $end] = DateTimes::dayBounds('2026-09-25', $tokyo);

    expect($start->toDateTimeString())->toBe('2026-09-24 15:00:00')
        ->and($end->toDateTimeString())->toBe('2026-09-25 14:59:59')
        ->and($start->timezoneName)->toBe('UTC');
});

test('the profile saves the time zone, and blank clears it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')
        ->assertSeeHtml('data-user-time-zone')
        ->set('time_zone', 'Asia/Tokyo')->call('savePreferences')->assertHasNoErrors();
    expect($user->fresh()->time_zone)->toBe('Asia/Tokyo');

    Livewire::actingAs($user->fresh())->test('profile.index')
        ->assertSet('time_zone', 'Asia/Tokyo')
        ->set('time_zone', '')->call('savePreferences')->assertHasNoErrors();
    expect($user->fresh()->time_zone)->toBeNull();

    Livewire::actingAs($user)->test('profile.index')
        ->set('time_zone', 'Mars/Olympus')->call('savePreferences')->assertHasErrors('time_zone');
});

test('an admin sets the default time zone for users', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('default_users_time_zone', '')
        ->set('default_users_time_zone', 'Europe/Paris')->call('save')->assertHasNoErrors();
    expect(Setting::get('default_users_time_zone'))->toBe('Europe/Paris');

    Livewire::actingAs($admin)->test('settings.index')
        ->set('default_users_time_zone', 'Nowhere/Land')->call('save')->assertHasErrors('default_users_time_zone');

    Livewire::actingAs($admin)->test('settings.index')
        ->set('default_users_time_zone', '')->call('save')->assertHasNoErrors();
    expect(Setting::get('default_users_time_zone'))->toBe('')
        ->and(TimeZones::default())->toBe('UTC');
});

test('the zone options are labelled with their offset', function () {
    $options = TimeZones::options();

    expect($options)->toHaveKey('Asia/Tokyo')
        ->and($options['Asia/Tokyo'])->toBe('(UTC+09:00) Asia/Tokyo')
        ->and($options['UTC'])->toBe('(UTC+00:00) UTC')
        ->and(reset($options))->toStartWith('(UTC-')
        ->and(end($options))->toStartWith('(UTC+');
});
