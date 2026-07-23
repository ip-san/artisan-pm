<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('group totals reflect the full filtered set, not just the current page', function () {
    Setting::set('default_issues_per_page', 5);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory(8)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id');

    // Only 5 of the 8 issues are on the current page, but the group total
    // must reflect all 8 — the whole point of computing it via SQL rather
    // than counting the already-paginated in-memory collection.
    expect($component->instance()->issues->count())->toBe(5)
        ->and($component->instance()->groupTotals[$status->name]['count'])->toBe(8);
});

test('group totals use the friendly assigned_to_id placeholder for unassigned issues', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    Issue::factory(3)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
        'assigned_to_id' => null,
    ]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('groupBy', 'assigned_to_id');

    expect($component->instance()->groupTotals['未割当']['count'])->toBe(3);
});

test('group totals reflect the full filtered set when grouping by a list-format custom field', function () {
    Setting::set('default_issues_per_page', 5);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $field = CustomField::factory()->list(['Small', 'Large'])->create();
    $field->trackers()->attach($tracker);

    $smallIssues = Issue::factory(6)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);
    $smallIssues->each(fn (Issue $issue) => $issue->setCustomFieldValues([$field->id => 'Small']));

    $largeIssue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);
    $largeIssue->setCustomFieldValues([$field->id => 'Large']);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', "cf_{$field->id}");

    // Only 5 of the 6 "Small" issues are on the current page, but the
    // group total must reflect all 6.
    expect($component->instance()->issues->count())->toBe(5)
        ->and($component->instance()->groupTotals['Small']['count'])->toBe(6)
        ->and($component->instance()->groupTotals['Large']['count'])->toBe(1);
});

test('group totals resolve enumeration-format custom field values to their option names', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $field->trackers()->attach($tracker);
    $option = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'High priority']);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);
    $issue->setCustomFieldValues([$field->id => (string) $option->id]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('groupBy', "cf_{$field->id}");

    expect($component->instance()->groupTotals['High priority']['count'])->toBe(1);
});

test('issues with no value for the grouped custom field fall into the empty group', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);

    Issue::factory(2)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('groupBy', "cf_{$field->id}");

    expect($component->instance()->groupTotals['']['count'])->toBe(2);
});

test('a multiple-value custom field is not offered as a groupBy option and yields no totals', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $field = CustomField::factory()->multiple()->list(['A', 'B'])->create();
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);
    $issue->setCustomFieldValues([$field->id => ['A', 'B']]);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('groupBy', "cf_{$field->id}");

    expect($component->instance()->groupTotals->isEmpty())->toBeTrue();
});
