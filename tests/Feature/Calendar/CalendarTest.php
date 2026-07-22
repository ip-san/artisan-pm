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

    expect($matchingDay['entries']->pluck('issue.id'))->toContain($issue->id);
});

test('an issue due in a different month does not appear', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    Issue::factory()->for($project)->create(['due_date' => now()->addMonths(2)->toDateString()]);

    $component = Livewire::actingAs($user)->test('calendar.index', ['project' => $project]);
    $allIssueIds = collect($component->get('weeks'))->flatten(1)->flatMap(fn ($day) => $day['entries']->pluck('issue.id'));

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

test('an issue spanning several days is marked on its start date and due date only', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    $start = now()->startOfMonth()->addDays(3);
    $due = now()->startOfMonth()->addDays(9);
    $issue = Issue::factory()->for($project)->create([
        'start_date' => $start->toDateString(),
        'due_date' => $due->toDateString(),
    ]);

    $days = collect(Livewire::actingAs($user)->test('calendar.index', ['project' => $project])->get('weeks'))->flatten(1);

    $markersByDate = $days
        ->flatMap(fn ($day) => $day['entries']->map(fn ($entry) => [
            'date' => $day['date']->toDateString(),
            'issue_id' => $entry['issue']->id,
            'marker' => $entry['marker'],
        ]))
        ->where('issue_id', $issue->id);

    expect($markersByDate)->toHaveCount(2)
        ->and($markersByDate->firstWhere('marker', 'start')['date'])->toBe($start->toDateString())
        ->and($markersByDate->firstWhere('marker', 'due')['date'])->toBe($due->toDateString());
});

test('an issue starting and due the same day collapses to a single combined marker', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    $day = now()->startOfMonth()->addDays(5);
    $issue = Issue::factory()->for($project)->create([
        'start_date' => $day->toDateString(),
        'due_date' => $day->toDateString(),
    ]);

    $entries = collect(Livewire::actingAs($user)->test('calendar.index', ['project' => $project])->get('weeks'))
        ->flatten(1)
        ->flatMap(fn ($d) => $d['entries'])
        ->where(fn ($entry) => $entry['issue']->id === $issue->id);

    expect($entries)->toHaveCount(1)
        ->and($entries->first()['marker'])->toBe('both');
});

test('an issue with only a start date in the month appears on that start date', function () {
    $project = Project::factory()->create();
    $user = calendarMember($project);
    $start = now()->startOfMonth()->addDays(7);
    $issue = Issue::factory()->for($project)->create([
        'start_date' => $start->toDateString(),
        'due_date' => null,
    ]);

    $days = collect(Livewire::actingAs($user)->test('calendar.index', ['project' => $project])->get('weeks'))->flatten(1);
    $matchingDay = $days->first(fn ($d) => $d['date']->isSameDay($start));

    expect($matchingDay['entries']->pluck('issue.id'))->toContain($issue->id)
        ->and($matchingDay['entries']->firstWhere('issue.id', $issue->id)['marker'])->toBe('start');
});
