<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
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

/**
 * A project issue with a date custom field set to 2026-09-04, and a member
 * who can see and edit it and see spent time.
 *
 * @return array{project: Project, issue: Issue, field: CustomField, viewer: User}
 */
function dateFieldIssue(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues', 'view_time_entries', 'log_time', 'view_gantt', 'view_calendar']])
    );
    $field = CustomField::factory()->create(['name' => 'Deadline', 'field_format' => CustomFieldFormat::Date->value]);
    $field->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value])->id,
        'start_date' => '2026-09-01',
        'due_date' => '2026-09-30',
    ]);
    $issue->setCustomFieldValues([$field->id => '2026-09-04'], collect([$field]));

    return ['project' => $project, 'issue' => $issue->fresh(), 'field' => $field, 'viewer' => $viewer];
}

test('a date custom field value is shown in the date format while its form input stays ISO', function () {
    ['project' => $project, 'issue' => $issue, 'field' => $field, 'viewer' => $viewer] = dateFieldIssue();

    expect($issue->customDisplayValue($field))->toBe('2026-09-04');

    Setting::set('date_format', '%d/%m/%Y');

    expect($issue->customDisplayValue($field))->toBe('04/09/2026')
        ->and((string) $issue->customFieldFormValues(collect([$field]))[$field->id])->toStartWith('2026-09-04');

    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('04/09/2026');
    Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', "cf_{$field->id}"])
        ->assertSee('04/09/2026');
});

test('a date custom field change in the issue history is shown in the date format', function () {
    ['field' => $field] = dateFieldIssue();
    Setting::set('date_format', '%d.%m.%Y');

    $detail = new JournalDetail(['property' => 'cf', 'prop_key' => (string) $field->id, 'old_value' => '2026-09-04 00:00:00', 'new_value' => '2026-09-05']);
    $text = CustomField::factory()->create(['field_format' => CustomFieldFormat::String->value]);
    $other = new JournalDetail(['property' => 'cf', 'prop_key' => (string) $text->id, 'old_value' => '2026-09-04']);

    expect($detail->displayValue($detail->old_value))->toBe('04.09.2026')
        ->and($detail->displayValue($detail->new_value))->toBe('05.09.2026')
        ->and($other->displayValue($other->old_value))->toBe('2026-09-04');
});

test('the time report headings, the gantt months and the calendar heading follow the date format', function () {
    ['project' => $project, 'viewer' => $viewer] = dateFieldIssue();
    TimeEntry::factory()->for($project)->for($viewer)->create([
        'activity_id' => Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value])->id,
        'spent_on' => '2026-09-04', 'hours' => 2,
    ]);

    $report = fn (string $period) => collect(Livewire::actingAs($viewer)->test('time-entries.report', ['project' => $project])
        ->set('criteria', ['user'])->set('period', $period)->instance()->report->periods)->pluck('label')->all();

    expect($report('day'))->toBe(['2026-09-04'])->and($report('month'))->toBe(['2026-09']);

    Setting::set('date_format', '%d %B %Y');
    app()->setLocale('en');

    expect($report('day'))->toBe(['04 September 2026'])->and($report('month'))->toBe(['September 2026'])
        ->and(DateTimes::month('2026-09-04'))->toBe('September 2026');

    Setting::set('date_format', '%m/%d/%Y');
    expect(collect(Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])->instance()->monthBands)->pluck('label')->all())->toBe(['09/2026']);

    Livewire::actingAs($viewer)->test('calendar.index', ['project' => $project])->set('year', 2026)->set('month', 9)->assertSee('09/2026');
});

test('the admin user form sets a user\'s language and time zone', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['language' => null, 'time_zone' => null]);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->assertSet('language', '')->assertSet('time_zone', '')
        ->set('language', 'en')->set('time_zone', 'Asia/Tokyo')
        ->call('save')->assertHasNoErrors();

    expect($user->fresh()->only(['language', 'time_zone']))->toBe(['language' => 'en', 'time_zone' => 'Asia/Tokyo']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('time_zone', 'Mars/Olympus')->set('language', 'xx')
        ->call('save')->assertHasErrors(['time_zone', 'language']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('language', '')->set('time_zone', '')
        ->call('save')->assertHasNoErrors();

    expect($user->fresh()->only(['language', 'time_zone']))->toBe(['language' => null, 'time_zone' => null]);
});
