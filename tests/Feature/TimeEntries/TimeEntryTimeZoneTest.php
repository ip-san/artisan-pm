<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Dashboard\Blocks\TimeEntriesBlock;
use App\Support\TimeLog\TimeLogConstraints;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// ISO dates, so the expectations read as the stored values (an empty setting follows the language).
beforeEach(function () {
    Setting::set('date_format', '%Y-%m-%d');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<int, string>  $permissions
 */
function zonedTimeMember(Project $project, string $zone, array $permissions = ['view_project', 'view_issues', 'view_time_entries', 'log_time']): User
{
    $user = User::factory()->create(['time_zone' => $zone]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('a new time entry is dated the viewer today', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    $project = Project::factory()->create();

    Livewire::actingAs(zonedTimeMember($project, 'Asia/Tokyo'))->test('time-entries.form', ['project' => $project])
        ->assertSet('spent_on', '2026-09-25');
    Livewire::actingAs(zonedTimeMember($project, 'America/Los_Angeles'))->test('time-entries.form', ['project' => $project])
        ->assertSet('spent_on', '2026-09-24');
});

test('a future date is judged by the time entry user today', function () {
    // 2026-09-24 in Tokyo, still 2026-09-23 in Los Angeles.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC'));
    Setting::set('timelog_accept_future_dates', false);
    $project = Project::factory()->create();
    $errors = function (User $user, string $spentOn): array {
        try {
            TimeLogConstraints::assertSatisfied(['user_id' => $user->id, 'hours' => 1, 'spent_on' => $spentOn]);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    };

    expect($errors(zonedTimeMember($project, 'Asia/Tokyo'), '2026-09-24'))->toBe([])
        ->and($errors(zonedTimeMember($project, 'America/Los_Angeles'), '2026-09-24'))->toHaveKey('spent_on')
        ->and($errors(zonedTimeMember($project, 'America/Los_Angeles'), '2026-09-23'))->toBe([]);
});

test('the time entry list shows its date as stored and its creation time in the viewer zone', function () {
    $project = Project::factory()->create();
    $entry = TimeEntry::factory()->for($project)->create(['spent_on' => '2026-09-24', 'issue_id' => Issue::factory()->for($project)->create()->id]);
    $entry->forceFill(['created_at' => '2026-09-24 23:30:00'])->saveQuietly();

    $component = Livewire::actingAs(zonedTimeMember($project, 'Pacific/Honolulu'))->test('time-entries.index', ['project' => $project]);

    expect($component->instance()->columnValue($entry->fresh(), 'spent_on'))->toBe('2026-09-24')
        ->and($component->instance()->columnValue($entry->fresh(), 'created_at'))->toBe('2026-09-24 13:30');
});

test('the activity groups entries by the viewer day, and logged time stays on its own date', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'UTC'));
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    Issue::query()->whereKey($issue->id)->update(['created_at' => '2026-09-24 23:30:00']);
    TimeEntry::factory()->for($project)->create(['spent_on' => '2026-09-24', 'issue_id' => $issue->id]);

    $tokyo = Livewire::actingAs(zonedTimeMember($project, 'Asia/Tokyo'))->test('activity.index', ['project' => $project])
        ->set('activeTypes', ['issue', 'time-entry']);
    $groups = $tokyo->get('groupedEntries')->map(fn ($entries) => $entries->pluck('type')->all())->all();
    expect($groups)->toBe(['2026-09-25' => ['issue'], '2026-09-24' => ['time-entry']]);

    $losAngeles = Livewire::actingAs(zonedTimeMember($project, 'America/Los_Angeles'))->test('activity.index', ['project' => $project])
        ->set('activeTypes', ['issue', 'time-entry']);
    $groups = $losAngeles->get('groupedEntries')->map(fn ($entries) => $entries->pluck('type')->sort()->values()->all())->all();
    expect($groups)->toBe(['2026-09-24' => ['issue', 'time-entry']]);
});

test('the my page time block counts days back from the user today', function () {
    // 2026-09-25 in Tokyo.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    $project = Project::factory()->create();
    $user = zonedTimeMember($project, 'Asia/Tokyo');
    TimeEntry::factory()->for($project)->for($user)->create(['spent_on' => '2026-09-24']);
    TimeEntry::factory()->for($project)->for($user)->create(['spent_on' => '2026-09-23']);

    $rows = app(TimeEntriesBlock::class)->rowsWithSettings($user, ['days' => 2]);

    expect($rows->pluck('meta')->all())->toBe(['2026-09-24']);
});
