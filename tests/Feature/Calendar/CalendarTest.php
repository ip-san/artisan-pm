<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function calendarMember(Project $project, array $permissions = ['view_calendar']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_calendar can see the calendar', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);

    Livewire::actingAs($user)->test('calendar.index', ['project' => $project])->assertOk();
});

test('a member without view_calendar is forbidden', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project, []);

    Livewire::actingAs($user)->test('calendar.index', ['project' => $project])->assertForbidden();
});

test('an issue appears on the calendar cell matching its due date', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    $dueDate = now()->startOfMonth()->addDays(10);
    $issue = Issue::factory()->for($project)->create(['due_date' => $dueDate->toDateString()]);

    $component = Livewire::actingAs($user)
        ->test('calendar.index', ['project' => $project])
        ->set('year', $dueDate->year)
        ->set('month', $dueDate->month)
        ->call('previousMonth')
        ->call('nextMonth');

    $weeks = $component->get('weeks');
    $matchingDay = collect($weeks)->flatten(1)->first(fn ($day) => $day['date']->isSameDay($dueDate));

    expect($matchingDay['issues']->pluck('id'))->toContain($issue->id);
});

test('an issue due in a different month does not appear', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    Issue::factory()->for($project)->create(['due_date' => now()->addMonths(2)->toDateString()]);

    $component = Livewire::actingAs($user)->test('calendar.index', ['project' => $project]);
    $allIssueIds = collect($component->get('weeks'))->flatten(1)->flatMap(fn ($day) => $day['issues']->pluck('id'));

    expect($allIssueIds)->toBeEmpty();
});

test('navigating to the next and previous month updates the displayed year and month', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);

    $component = Livewire::actingAs($user)
        ->test('calendar.index', ['project' => $project])
        ->set('year', 2026)
        ->set('month', 12)
        ->call('nextMonth');

    expect($component->get('year'))->toBe(2027)
        ->and($component->get('month'))->toBe(1);

    $component->call('previousMonth');

    expect($component->get('year'))->toBe(2026)
        ->and($component->get('month'))->toBe(12);
});

test('days outside the current month are flagged as such', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);

    $component = Livewire::actingAs($user)
        ->test('calendar.index', ['project' => $project])
        ->set('year', 2026)
        ->set('month', 7);

    $weeks = $component->get('weeks');
    $firstDay = $weeks[0][0];

    if ($firstDay['date']->month !== 7) {
        expect($firstDay['isCurrentMonth'])->toBeFalse();
    }

    $julyDay = collect($weeks)->flatten(1)->first(fn ($day) => $day['date']->day === 15 && $day['date']->month === 7);
    expect($julyDay['isCurrentMonth'])->toBeTrue();
});
