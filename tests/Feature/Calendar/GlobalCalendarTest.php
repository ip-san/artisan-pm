<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function globalCalendarMember(Project $project, array $permissions = ['view_calendar']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the global calendar shows issues from every project the user can view_calendar in', function () {
    $visibleProject = Project::factory()->create();
    $hiddenProject = Project::factory()->create();
    $user = globalCalendarMember($visibleProject);

    $visible = Issue::factory()->for($visibleProject)->create(['due_date' => now()->startOfMonth()->addDays(5)]);
    $hidden = Issue::factory()->for($hiddenProject)->create(['due_date' => now()->startOfMonth()->addDays(5)]);

    $days = collect(Livewire::actingAs($user)->test('calendar.global-index')->get('weeks'))->flatten(1);
    $ids = $days->flatMap(fn ($day) => $day['entries'])->map(fn ($entry) => $entry['issue']->id);

    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

test('the start_of_week setting also shifts the global calendar grid', function () {
    Setting::set('start_of_week', 1);

    $project = Project::factory()->create();
    $user = globalCalendarMember($project);

    $component = Livewire::actingAs($user)
        ->test('calendar.global-index')
        ->set('year', 2026)
        ->set('month', 7);

    $weeks = $component->get('weeks');

    expect($weeks[0][0]['date']->dayOfWeek)->toBe(1)
        ->and($component->get('weekdayLabels'))->toBe(['月', '火', '水', '木', '金', '土', '日']);
});

test('cross-project visibility is bucketed per project: all-visibility here, own-only there', function () {
    $allProject = Project::factory()->create();
    $ownProject = Project::factory()->create();
    $dueDate = now()->startOfMonth()->addDays(5);

    $user = globalCalendarMember($allProject, ['view_calendar', 'view_issues']);
    Member::factory()->for($ownProject)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_calendar', 'view_issues'], 'issues_visibility' => 'own'])
    );

    $other = User::factory()->create();
    $othersIssueInAllProject = Issue::factory()->for($allProject)->create(['due_date' => $dueDate, 'author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $othersIssueInOwnProject = Issue::factory()->for($ownProject)->create(['due_date' => $dueDate, 'author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $myIssueInOwnProject = Issue::factory()->for($ownProject)->create(['due_date' => $dueDate, 'author_id' => $user->id]);

    $days = collect(Livewire::actingAs($user)->test('calendar.global-index')->get('weeks'))->flatten(1);
    $ids = $days->flatMap(fn ($day) => $day['entries'])->map(fn ($entry) => $entry['issue']->id);

    expect($ids)->toContain($othersIssueInAllProject->id)
        ->toContain($myIssueInOwnProject->id)
        ->not->toContain($othersIssueInOwnProject->id);
});

test('navigating to the next and previous month updates the displayed year and month on the global calendar', function () {
    $project = Project::factory()->create();
    $user = globalCalendarMember($project);

    $component = Livewire::actingAs($user)
        ->test('calendar.global-index')
        ->set('year', 2026)
        ->set('month', 3)
        ->call('nextMonth');

    expect($component->get('year'))->toBe(2026)
        ->and($component->get('month'))->toBe(4);
});

test('a guest is redirected to login when visiting the global calendar', function () {
    $this->get(route('calendar.global-index'))->assertRedirect(route('login'));
});

test('an active filter restricts the global calendar to matching issues', function () {
    $project = Project::factory()->create();
    $user = globalCalendarMember($project);
    $matchingStatus = IssueStatus::factory()->create();
    $otherStatus = IssueStatus::factory()->create();
    $day = now()->startOfMonth()->addDays(5);

    $matching = Issue::factory()->for($project)->create(['status_id' => $matchingStatus->id, 'due_date' => $day->toDateString()]);
    $excluded = Issue::factory()->for($project)->create(['status_id' => $otherStatus->id, 'due_date' => $day->toDateString()]);

    $weeks = Livewire::actingAs($user)
        ->test('calendar.global-index')
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $matchingStatus->id)
        ->call('applyFilters')
        ->get('weeks');

    $matchingDay = collect($weeks)->flatten(1)->first(fn ($d) => $d['date']->isSameDay($day));
    $ids = $matchingDay['entries']->pluck('issue.id');

    expect($ids)->toContain($matching->id)->not->toContain($excluded->id);
});
