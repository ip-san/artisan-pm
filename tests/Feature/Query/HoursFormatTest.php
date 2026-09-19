<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Format\Hours;
use Livewire\Livewire;

test('decimal is the default and keeps the grouping the totals always had', function () {
    expect(Hours::timespanFormat())->toBe('decimal')
        ->and(Hours::format(1.5))->toBe('1.50')
        ->and(Hours::format(1234.5))->toBe('1,234.50')
        ->and(Hours::format(1234.5, grouped: false))->toBe('1234.50')
        ->and(Hours::format('2.25'))->toBe('2.25')
        ->and(Hours::format(null))->toBe('')
        ->and(Hours::format(''))->toBe('');
});

test('minutes format shows hours and two-digit minutes like Redmine', function () {
    Setting::set('timespan_format', 'minutes');

    expect(Hours::format(1.5))->toBe('1:30')
        ->and(Hours::format(0.25))->toBe('0:15')
        ->and(Hours::format(0))->toBe('0:00')
        ->and(Hours::format(10.9999))->toBe('11:00')
        ->and(Hours::format(-1.5))->toBe('-1:30')
        ->and(Hours::format(1234.5, grouped: false))->toBe('1234:30');
});

test('an unknown stored value falls back to decimal', function () {
    Setting::set('timespan_format', 'sexagesimal');

    expect(Hours::timespanFormat())->toBe('decimal');
});

test('the issue and time entry pages show hours in the chosen format', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries']])
    );
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'estimated_hours' => 2.5,
        'done_ratio' => 0,
    ]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'hours' => 1.75]);

    Setting::set('timespan_format', 'minutes');

    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('2:30 時間')->assertSee('1:45 時間');
    $entries = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project]);
    $entries->assertSee('合計: 1:45 時間');
    expect($entries->instance()->columnValue($entries->get('timeEntries')->first(), 'hours'))->toBe('1:45');

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');
    $list->assertSee('予定工数 2:30');
});

test('the settings form saves the format and rejects others', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('timespan_format', 'minutes')->call('save')->assertHasNoErrors();
    expect(Setting::get('timespan_format'))->toBe('minutes');

    Livewire::actingAs($admin)->test('settings.index')->set('timespan_format', 'roman')->call('save')->assertHasErrors(['timespan_format']);
});
