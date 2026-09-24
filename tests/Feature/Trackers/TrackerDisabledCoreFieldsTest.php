<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can disable core fields for a tracker', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('trackers.form', ['tracker' => $tracker])
        ->set('disabled_core_fields', ['category_id', 'estimated_hours'])
        ->call('save');

    expect($tracker->fresh()->disabled_core_fields)->toBe(['category_id', 'estimated_hours']);
});

test('an invalid field key is rejected', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('trackers.form', ['tracker' => $tracker])
        ->set('disabled_core_fields', ['not_a_real_field'])
        ->call('save')
        ->assertHasErrors(['disabled_core_fields.*']);
});

test('Tracker::isCoreFieldDisabled reports disabled fields correctly', function () {
    $tracker = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'due_date']]);

    expect($tracker->isCoreFieldDisabled('category_id'))->toBeTrue()
        ->and($tracker->isCoreFieldDisabled('due_date'))->toBeTrue()
        ->and($tracker->isCoreFieldDisabled('priority_id'))->toBeFalse();
});

test('a tracker with no disabled_core_fields set disables nothing', function () {
    $tracker = Tracker::factory()->create(['disabled_core_fields' => null]);

    expect($tracker->isCoreFieldDisabled('category_id'))->toBeFalse();
});

test('the issue form hides a disabled core field', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'estimated_hours']]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->assertDontSee('予定工数(時間)')
        ->assertSee('優先度');
});

test('the issue form shows a field once it is no longer disabled', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['disabled_core_fields' => []]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->assertSee('予定工数(時間)');
});

test('saving an issue still works when its tracker hides some core fields', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create([
        'disabled_core_fields' => ['category_id', 'estimated_hours'],
        'default_status_id' => IssueStatus::factory()->create()->id,
    ]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', Enumeration::factory()->create()->id)
        ->set('subject', 'Issue with hidden fields')
        ->call('save')
        ->assertHasNoErrors();

    $issue = Issue::where('subject', 'Issue with hidden fields')->firstOrFail();

    expect($issue->category_id)->toBeNull()
        ->and($issue->estimated_hours)->toBeNull();
});

/**
 * A1-45: IssueQuery#available_columns drops the columns of core fields
 * every tracker of the list disables.
 */
test('the issue list drops the columns of core fields every tracker disables, estimated hours with its totals', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'estimated_hours', 'priority_id', 'parent_id', 'due_date']]);
    $task = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'estimated_hours', 'priority_id', 'parent_id']]);
    $project->trackers()->sync([$bug->id, $task->id]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'subject' => 'Listed issue']);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project->fresh()])
        ->set('columns', ['subject', 'category_id', 'estimated_hours', 'total_estimated_hours', 'estimated_remaining_hours', 'priority_id', 'due_date', 'parent_id']);

    expect(array_keys($list->get('availableColumns')))
        ->not->toContain('category_id', 'estimated_hours', 'total_estimated_hours', 'estimated_remaining_hours', 'priority_id')
        ->toContain('due_date', 'parent_id', 'subject')
        ->and(array_keys($list->get('sortableColumns')))->not->toContain('category_id')
        ->and($list->get('shownColumns'))->toBe(['subject', 'due_date', 'parent_id']);

    $list->assertSee('Listed issue')
        ->assertSeeHtml('wire:key="column-heading-due_date"')
        ->assertDontSeeHtml('wire:key="column-heading-estimated_hours"')
        ->assertDontSeeHtml('wire:key="column-heading-category_id"')
        ->assertDontSeeHtml('value="priority_id" wire:key="group-by-priority_id"');

    $list->call('exportCsv')->assertFileDownloaded("{$project->identifier}-issues.csv");
});

test('grouping by a core field every tracker disables falls back to no grouping', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['disabled_core_fields' => ['priority_id']]);
    $project->trackers()->sync([$tracker->id]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project->fresh()])->set('groupBy', 'priority_id');

    expect($list->get('groupedIssues')->keys()->all())->toBe([''])
        ->and($list->get('groupTotals'))->toBeEmpty();
});

test('a column stays while one tracker of the list still uses its field, and on the cross-project list', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create(['disabled_core_fields' => ['category_id']]);
    $task = Tracker::factory()->create(['disabled_core_fields' => []]);
    $project->trackers()->sync([$bug->id, $task->id]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    expect(array_keys(Livewire::actingAs($user)->test('issues.index', ['project' => $project->fresh()])->get('availableColumns')))->toContain('category_id');

    $project->trackers()->sync([$bug->id]);

    expect(Livewire::actingAs($user)->test('issues.global-index')->set('columns', ['subject', 'category_id'])->get('shownColumns'))->toBe(['subject']);
});
