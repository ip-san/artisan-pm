<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function totalsListMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'view_time_entries']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function totalsActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

test('the issue list shows estimated and spent hour totals for the filtered set', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $issueA = Issue::factory()->for($project)->create(['estimated_hours' => 4]);
    $issueB = Issue::factory()->for($project)->create(['estimated_hours' => 2.5]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $issueA->id, 'activity_id' => $activity->id, 'hours' => 3]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $issueB->id, 'activity_id' => $activity->id, 'hours' => 1.5]);

    $totals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->get('listTotals');

    expect($totals['estimated'])->toBe(6.5)
        ->and($totals['spent'])->toBe(4.5);
});

test('totals respect the active filters, not the whole project', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();
    $matching = Issue::factory()->for($project)->create(['status_id' => $statusA->id, 'estimated_hours' => 5]);
    $excluded = Issue::factory()->for($project)->create(['status_id' => $statusB->id, 'estimated_hours' => 7]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $matching->id, 'activity_id' => $activity->id, 'hours' => 2]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $excluded->id, 'activity_id' => $activity->id, 'hours' => 9]);

    $totals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $statusA->id)
        ->call('applyFilters')
        ->get('listTotals');

    expect($totals['estimated'])->toBe(5.0)
        ->and($totals['spent'])->toBe(2.0);
});

test('group headings carry per-group counts plus estimated and spent totals', function () {
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $activity = totalsActivity();

    $statusNew = IssueStatus::factory()->create(['name' => 'New']);
    $statusDone = IssueStatus::factory()->create(['name' => 'Done']);
    $newA = Issue::factory()->for($project)->create(['status_id' => $statusNew->id, 'estimated_hours' => 3]);
    Issue::factory()->for($project)->create(['status_id' => $statusNew->id, 'estimated_hours' => 1]);
    Issue::factory()->for($project)->create(['status_id' => $statusDone->id, 'estimated_hours' => 8]);
    TimeEntry::factory()->for($project)->for($user)->create(['issue_id' => $newA->id, 'activity_id' => $activity->id, 'hours' => 2.5]);

    $groupTotals = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id')
        ->get('groupTotals');

    expect($groupTotals['New']['count'])->toBe(2)
        ->and($groupTotals['New']['estimated'])->toBe(4.0)
        ->and($groupTotals['New']['spent'])->toBe(2.5)
        ->and($groupTotals['Done']['count'])->toBe(1)
        ->and($groupTotals['Done']['estimated'])->toBe(8.0)
        ->and($groupTotals['Done']['spent'])->toBe(0.0);
});

/**
 * @return array{0: Project, 1: User, 2: CustomField, 3: IssueStatus, 4: IssueStatus}
 */
function totalsGroupedSetup(): array
{
    $project = Project::factory()->create();
    $user = totalsListMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $points = CustomField::factory()->create(['name' => 'Points', 'field_format' => CustomFieldFormat::Int->value]);
    $points->trackers()->attach($tracker);
    $open = IssueStatus::factory()->create(['name' => 'Open']);
    $doing = IssueStatus::factory()->create(['name' => 'Doing']);
    auth()->setUser(User::factory()->admin()->create());

    foreach ([[$open, 3, 4, 0], [$open, 5, 2, 50], [$doing, 7, 10, 0]] as [$status, $value, $estimate, $done]) {
        Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id, 'estimated_hours' => $estimate, 'done_ratio' => $done])
            ->setCustomFieldValues([$points->id => (string) $value]);
    }

    auth()->logout();

    return [$project, $user, $points, $open, $doing];
}

test('each group heading shows the chosen totals, custom fields and remaining hours included', function () {
    [$project, $user, $points] = totalsGroupedSetup();

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id')
        ->set('totals', ['estimated_remaining_hours', "cf_{$points->id}"]);

    $groups = $list->get('groupTotals');

    expect($groups['Open']['custom'][$points->id])->toBe(8.0)
        ->and($groups['Open']['remaining'])->toBe(5.0)
        ->and($groups['Doing']['custom'][$points->id])->toBe(7.0)
        ->and($list->get('listTotals')['custom'][$points->id])->toBe(15.0);

    $list->assertSeeHtml('data-group-totals')->assertSee('Points 8');
});

test('the totals can be chosen per list and are kept in a saved query', function () {
    [$project, $user, $points] = totalsGroupedSetup();
    Setting::set('issue_list_default_totals', ['estimated_hours']);
    Member::query()->where('user_id', $user->id)->first()->roles()->first()->update(['permissions' => ['view_issues', 'view_time_entries', 'save_queries']]);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');

    expect($list->get('totalNames'))->toBe(['estimated_hours']);

    $list->call('toggleTotal', "cf_{$points->id}")->call('toggleTotal', 'estimated_hours');

    expect($list->get('totalNames'))->toBe(["cf_{$points->id}"]);

    $list->set('newQueryName', 'Points only')->call('saveQuery')->assertHasNoErrors();
    $saved = Query::query()->where('name', 'Points only')->sole();

    expect($saved->options['totalable_names'])->toBe(["cf_{$points->id}"]);

    $fresh = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->call('loadQuery', $saved->id);

    expect($fresh->get('totalNames'))->toBe(["cf_{$points->id}"]);
});
