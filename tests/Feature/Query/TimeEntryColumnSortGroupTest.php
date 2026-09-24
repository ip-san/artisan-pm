<?php

use App\Enums\CustomFieldFormat;
use App\Enums\IssueVisibility;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * A2-08c: sorting and grouping the time entry list by the issue and custom
 * field columns of A2-08b.
 */
function sortGroupMember(Project $project, string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_time_entries', 'view_issues'], 'issues_visibility' => $visibility])
    );

    return $user;
}

/**
 * @return array<int, int> entry ids in list order
 */
function sortedEntryIds(User $viewer, Project $project, string $sortKey, string $direction = 'asc', ?string $component = null): array
{
    $list = $component === null
        ? Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])
        : Livewire::actingAs($viewer)->test($component);

    return $list->set('columns', ['spent_on', $sortKey, 'hours'])->set('sortKey', $sortKey)->set('sortDirection', $direction)
        ->get('timeEntries')->getCollection()->pluck('id')->all();
}

test('the list sorts by the issue tracker, status and category, blanks first ascending', function () {
    $project = Project::factory()->create();
    $viewer = sortGroupMember($project);
    // Positions follow creation order (the models are sortable).
    $early = Tracker::factory()->create();
    $late = Tracker::factory()->create();
    $todo = IssueStatus::factory()->create();
    $doing = IssueStatus::factory()->create();
    $zeta = IssueCategory::factory()->for($project)->create(['name' => 'Zeta']);
    $alpha = IssueCategory::factory()->for($project)->create(['name' => 'Alpha']);
    $first = Issue::factory()->for($project)->create(['tracker_id' => $late->id, 'status_id' => $todo->id, 'category_id' => $alpha->id]);
    $second = Issue::factory()->for($project)->create(['tracker_id' => $early->id, 'status_id' => $doing->id, 'category_id' => $zeta->id]);
    $onFirst = TimeEntry::factory()->for($project)->create(['issue_id' => $first->id]);
    $onSecond = TimeEntry::factory()->for($project)->create(['issue_id' => $second->id]);
    $noIssue = TimeEntry::factory()->for($project)->create(['issue_id' => null]);

    expect(sortedEntryIds($viewer, $project, 'issue_tracker'))->toBe([$noIssue->id, $onSecond->id, $onFirst->id])
        ->and(sortedEntryIds($viewer, $project, 'issue_tracker', 'desc'))->toBe([$onFirst->id, $onSecond->id, $noIssue->id])
        ->and(sortedEntryIds($viewer, $project, 'issue_status'))->toBe([$noIssue->id, $onFirst->id, $onSecond->id])
        ->and(sortedEntryIds($viewer, $project, 'issue_category'))->toBe([$noIssue->id, $onFirst->id, $onSecond->id]);
});

test('issue and project custom field columns sort by their values', function () {
    $project = Project::factory()->create();
    $viewer = sortGroupMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $points = CustomField::factory()->create(['field_format' => CustomFieldFormat::Int->value, 'name' => 'Points']);
    $points->trackers()->attach($tracker);
    $big = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $small = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $big->setCustomFieldValues([$points->id => '20']);
    $small->setCustomFieldValues([$points->id => '3']);
    $onBig = TimeEntry::factory()->for($project)->create(['issue_id' => $big->id]);
    $onSmall = TimeEntry::factory()->for($project)->create(['issue_id' => $small->id]);

    expect(sortedEntryIds($viewer, $project, "issue_cf_{$points->id}"))->toBe([$onSmall->id, $onBig->id])
        ->and(sortedEntryIds($viewer, $project, "issue_cf_{$points->id}", 'desc'))->toBe([$onBig->id, $onSmall->id]);
});

test('values of issues the viewer may not see sort as blank', function () {
    $project = Project::factory()->create();
    $viewer = sortGroupMember($project, IssueVisibility::Own->value);
    $early = Tracker::factory()->create();
    $late = Tracker::factory()->create();
    $mine = Issue::factory()->for($project)->create(['tracker_id' => $late->id, 'author_id' => $viewer->id]);
    $hidden = Issue::factory()->for($project)->create(['tracker_id' => $early->id]);
    $onMine = TimeEntry::factory()->for($project)->create(['issue_id' => $mine->id]);
    $onHidden = TimeEntry::factory()->for($project)->create(['issue_id' => $hidden->id]);

    expect(sortedEntryIds($viewer, $project, 'issue_tracker'))->toBe([$onHidden->id, $onMine->id])
        ->and(sortedEntryIds($viewer, $project, 'issue_tracker', 'desc'))->toBe([$onMine->id, $onHidden->id]);
});

test('the list groups by the issue status and a custom field, on the project and cross-project lists', function () {
    $project = Project::factory()->create();
    $viewer = sortGroupMember($project);
    $open = IssueStatus::factory()->create(['name' => 'Open status']);
    $done = IssueStatus::factory()->create(['name' => 'Done status']);
    $billing = CustomField::factory()->list(['Billable', 'Internal'])->create(['customized_type' => 'time_entry', 'name' => 'Billing']);
    $notes = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Notes', 'field_format' => CustomFieldFormat::Text->value]);
    $a = TimeEntry::factory()->for($project)->create(['issue_id' => Issue::factory()->for($project)->create(['status_id' => $open->id])->id]);
    $b = TimeEntry::factory()->for($project)->create(['issue_id' => Issue::factory()->for($project)->create(['status_id' => $done->id])->id]);
    $a->setCustomFieldValues([$billing->id => 'Billable']);
    $b->setCustomFieldValues([$billing->id => 'Internal']);

    $list = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])
        ->assertSeeHtml('<option value="issue_status"')
        ->set('groupBy', 'issue_status');
    expect($list->instance()->extraColumns->groupableLabels())->toHaveKey("cf_{$billing->id}")->not->toHaveKey("cf_{$notes->id}");
    expect(array_keys($list->get('groupSubtotals')))->toEqualCanonicalizing(['Open status', 'Done status']);

    $global = Livewire::actingAs($viewer)->test('time-entries.global-index')->set('groupBy', "cf_{$billing->id}");
    expect(array_keys($global->get('groupSubtotals')))->toEqualCanonicalizing(['Billable', 'Internal']);
});
