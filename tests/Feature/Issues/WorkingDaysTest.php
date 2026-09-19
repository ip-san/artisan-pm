<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Calendar\WorkingDays;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function workingDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function workingDaysIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('with no non-working days every date is a working day (calendar arithmetic)', function () {
    expect(WorkingDays::nonWorkingWeekDays())->toBe([])
        ->and(WorkingDays::add(workingDay('2026-01-09'), 1)->toDateString())->toBe('2026-01-10')
        ->and(WorkingDays::between(workingDay('2026-01-05'), workingDay('2026-01-12')))->toBe(7)
        ->and(WorkingDays::nextWorkingDate(workingDay('2026-01-10'))->toDateString())->toBe('2026-01-10');
});

test('a Saturday and Sunday weekend is skipped when counting', function () {
    Setting::set('non_working_week_days', [6, 7]);

    expect(WorkingDays::add(workingDay('2026-01-09'), 1)->toDateString())->toBe('2026-01-12')
        ->and(WorkingDays::add(workingDay('2026-01-05'), 5)->toDateString())->toBe('2026-01-12')
        ->and(WorkingDays::add(workingDay('2026-01-05'), 12)->toDateString())->toBe('2026-01-21')
        ->and(WorkingDays::add(workingDay('2026-01-05'), 0)->toDateString())->toBe('2026-01-05')
        ->and(WorkingDays::between(workingDay('2026-01-05'), workingDay('2026-01-12')))->toBe(5)
        ->and(WorkingDays::between(workingDay('2026-01-02'), workingDay('2026-01-06')))->toBe(2)
        ->and(WorkingDays::between(workingDay('2026-01-12'), workingDay('2026-01-05')))->toBe(0)
        ->and(WorkingDays::nextWorkingDate(workingDay('2026-01-10'))->toDateString())->toBe('2026-01-12')
        ->and(WorkingDays::nextWorkingDate(workingDay('2026-01-07'))->toDateString())->toBe('2026-01-07')
        ->and(WorkingDays::isWorkingDay(workingDay('2026-01-11')))->toBeFalse();
});

test('a list naming all seven days is ignored, and junk values are dropped', function () {
    Setting::set('non_working_week_days', [1, 2, 3, 4, 5, 6, 7]);
    expect(WorkingDays::nonWorkingWeekDays())->toBe([]);

    Setting::set('non_working_week_days', ['6', '7', '9', 'x']);
    expect(WorkingDays::nonWorkingWeekDays())->toBe([6, 7]);
});

test('a successor is rescheduled onto working days and keeps its working duration', function () {
    Setting::set('non_working_week_days', [6, 7]);
    $project = Project::factory()->create();
    $user = User::factory()->admin()->create();
    $predecessor = workingDaysIssue($project, ['start_date' => '2026-01-05', 'due_date' => '2026-01-07']);
    // Friday to Tuesday: two working days apart.
    $successor = workingDaysIssue($project, ['start_date' => '2026-01-02', 'due_date' => '2026-01-06']);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-01-09'], $user);

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-01-12')
        ->and($successor->fresh()->due_date->toDateString())->toBe('2026-01-14');
});

test('the relation delay counts working days', function () {
    Setting::set('non_working_week_days', [6, 7]);
    $project = Project::factory()->create();
    $user = User::factory()->admin()->create();
    $predecessor = workingDaysIssue($project, ['due_date' => '2026-01-05']);
    $successor = workingDaysIssue($project);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes', 'delay' => 3]);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-01-08'], $user);

    // Thursday + 1 + 3 working days = Wednesday 2026-01-14.
    expect($successor->fresh()->start_date->toDateString())->toBe('2026-01-14');
});

test('with no non-working days the reschedule stays calendar-based', function () {
    $project = Project::factory()->create();
    $user = User::factory()->admin()->create();
    $predecessor = workingDaysIssue($project, ['due_date' => '2026-01-05']);
    $successor = workingDaysIssue($project, ['start_date' => '2026-01-02', 'due_date' => '2026-01-04']);
    IssueRelation::create(['issue_from_id' => $predecessor->id, 'issue_to_id' => $successor->id, 'relation_type' => 'precedes']);

    app(IssueService::class)->update($predecessor, ['due_date' => '2026-01-09'], $user);

    expect($successor->fresh()->start_date->toDateString())->toBe('2026-01-10')
        ->and($successor->fresh()->due_date->toDateString())->toBe('2026-01-12');
});

test('the settings page saves the non-working weekdays and rejects bad ones', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('non_working_week_days', ['6', '7'])->call('save')->assertHasNoErrors();
    expect(WorkingDays::nonWorkingWeekDays())->toBe([6, 7]);

    Livewire::actingAs($admin)->test('settings.index')->assertSet('non_working_week_days', ['6', '7'])->set('non_working_week_days', ['8'])->call('save')->assertHasErrors(['non_working_week_days.0']);
});
