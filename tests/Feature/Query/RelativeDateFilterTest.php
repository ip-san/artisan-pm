<?php

use App\Enums\FilterOperator;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\RelativeDateRange;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * Redmine's relative date operators (A17-08): today, this week, in N days ... on whole days, with
 * the week starting on the site's start_of_week.
 */
beforeEach(function () {
    // Wednesday 2026-10-14.
    Carbon::setTestNow('2026-10-14 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

test('each relative operator stands for the days Redmine gives it', function (FilterOperator $operator, array $values, ?string $first, ?string $last) {
    expect(RelativeDateRange::for($operator, $values))->toBe([$first, $last]);
})->with([
    'today' => [FilterOperator::Today, [], '2026-10-14', '2026-10-14'],
    'yesterday' => [FilterOperator::Yesterday, [], '2026-10-13', '2026-10-13'],
    'tomorrow' => [FilterOperator::Tomorrow, [], '2026-10-15', '2026-10-15'],
    'this week, Sunday first' => [FilterOperator::ThisWeek, [], '2026-10-11', '2026-10-17'],
    'last week' => [FilterOperator::LastWeek, [], '2026-10-04', '2026-10-10'],
    'last two weeks' => [FilterOperator::LastTwoWeeks, [], '2026-09-27', '2026-10-10'],
    'next week' => [FilterOperator::NextWeek, [], '2026-10-18', '2026-10-24'],
    'this month' => [FilterOperator::ThisMonth, [], '2026-10-01', '2026-10-31'],
    'last month' => [FilterOperator::LastMonth, [], '2026-09-01', '2026-09-30'],
    'next month' => [FilterOperator::NextMonth, [], '2026-11-01', '2026-11-30'],
    'this year' => [FilterOperator::ThisYear, [], '2026-01-01', '2026-12-31'],
    'in the last 7 days' => [FilterOperator::InTheLastDays, [7], '2026-10-07', '2026-10-14'],
    '3 days ago' => [FilterOperator::DaysAgo, [3], '2026-10-11', '2026-10-11'],
    'more than 3 days ago' => [FilterOperator::MoreThanDaysAgo, [3], null, '2026-10-11'],
    'in 3 days' => [FilterOperator::InDays, [3], '2026-10-17', '2026-10-17'],
    'in more than 3 days' => [FilterOperator::InMoreThanDays, [3], '2026-10-17', null],
    'in the next 3 days' => [FilterOperator::InTheNextDays, [3], '2026-10-14', '2026-10-17'],
]);

test('a week starts where the site says it does', function () {
    Setting::set('start_of_week', Carbon::MONDAY);

    expect(RelativeDateRange::for(FilterOperator::ThisWeek, []))->toBe(['2026-10-12', '2026-10-18'])
        ->and(RelativeDateRange::for(FilterOperator::LastWeek, []))->toBe(['2026-10-05', '2026-10-11']);

    Setting::set('start_of_week', Carbon::SATURDAY);

    expect(RelativeDateRange::for(FilterOperator::ThisWeek, []))->toBe(['2026-10-10', '2026-10-16']);
});

test('a non-relative operator has no range', function () {
    expect(RelativeDateRange::for(FilterOperator::Equals, ['2026-10-14']))->toBeNull();
});

test('the due date filter returns the issues in the chosen span, on the web list and over the API', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $make = fn (string $subject, ?string $due) => Issue::factory()->for($project)->create([
        'subject' => $subject, 'due_date' => $due,
        'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $yesterday = $make('yesterday', '2026-10-13');
    $today = $make('today', '2026-10-14');
    $inTwo = $make('in two days', '2026-10-16');
    $nextWeek = $make('next week', '2026-10-20');
    $none = $make('no due date', null);

    $due = IssueFilterFieldRegistry::forProject($project->fresh())->get('due_date');
    $matching = fn (FilterOperator $operator, array $values = []) => $due->apply(Issue::query()->where('project_id', $project->id), $operator, $values)->orderBy('id')->pluck('id')->all();

    expect($matching(FilterOperator::Today))->toBe([$today->id])
        ->and($matching(FilterOperator::ThisWeek))->toBe([$yesterday->id, $today->id, $inTwo->id])
        ->and($matching(FilterOperator::NextWeek))->toBe([$nextWeek->id])
        ->and($matching(FilterOperator::InTheNextDays, [3]))->toBe([$today->id, $inTwo->id])
        ->and($matching(FilterOperator::InMoreThanDays, [3]))->toBe([$nextWeek->id])
        ->and($matching(FilterOperator::MoreThanDaysAgo, [0]))->toBe([$yesterday->id, $today->id]);

    Passport::actingAs(User::factory()->admin()->create());
    $ids = fn (string $query) => collect($this->getJson("/api/v1/issues?project_id={$project->id}&status_id=*&{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('f[]=due_date&op[due_date]=nw'))->toBe([$nextWeek->id])
        ->and($ids('f[]=due_date&op[due_date]=%3Ct%2B&v[due_date][]=3'))->toBe([$today->id, $inTwo->id])
        ->and($ids('f[]=due_date&op[due_date]=%3Et%2B&v[due_date][]=3'))->toBe([$nextWeek->id]);
});

test('the filter builder asks for a number of days, not a date, for the day-count operators', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('issues.index', ['project' => $project])
        ->call('addFilter', 'due_date')
        ->set('filterOperators.due_date', FilterOperator::InTheNextDays->value);

    $component->assertSeeHtml('type="number" min="0" step="1" wire:model="filterValues.due_date.0"');

    $component->set('filterOperators.due_date', FilterOperator::ThisWeek->value)->assertDontSeeHtml('wire:model="filterValues.due_date.0"');
});
