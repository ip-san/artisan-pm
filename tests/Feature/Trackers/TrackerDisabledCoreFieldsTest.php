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
