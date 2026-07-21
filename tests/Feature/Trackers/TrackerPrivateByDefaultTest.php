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

test('an admin can enable private_by_default for a tracker', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('trackers.form', ['tracker' => $tracker])
        ->set('private_by_default', true)
        ->call('save');

    expect($tracker->fresh()->private_by_default)->toBeTrue();
});

test('a new issue defaults to private when its tracker is private_by_default and the user can set it', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create([
        'private_by_default' => true,
        'default_status_id' => IssueStatus::factory()->create()->id,
    ]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'set_issues_private']])
    );

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id);

    expect($component->get('is_private'))->toBeTrue();
});

test('private_by_default has no effect for a user without set_issues_private', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create([
        'private_by_default' => true,
        'default_status_id' => IssueStatus::factory()->create()->id,
    ]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id);

    expect($component->get('is_private'))->toBeFalse();
});

test('switching to a private_by_default tracker checks the box, switching away unchecks it', function () {
    $project = Project::factory()->create();
    $normalTracker = Tracker::factory()->create(['default_status_id' => IssueStatus::factory()->create()->id]);
    $privateTracker = Tracker::factory()->create([
        'private_by_default' => true,
        'default_status_id' => IssueStatus::factory()->create()->id,
    ]);
    $project->trackers()->attach([$normalTracker->id, $privateTracker->id]);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'set_issues_private']])
    );

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $normalTracker->id);

    expect($component->get('is_private'))->toBeFalse();

    $component->set('tracker_id', $privateTracker->id);
    expect($component->get('is_private'))->toBeTrue();
});

test('saving an issue with a private_by_default tracker persists is_private', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create([
        'private_by_default' => true,
        'default_status_id' => IssueStatus::factory()->create()->id,
    ]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'set_issues_private']])
    );

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', Enumeration::factory()->create()->id)
        ->set('subject', 'A private-by-default issue')
        ->call('save')
        ->assertHasNoErrors();

    $issue = Issue::where('subject', 'A private-by-default issue')->firstOrFail();

    expect($issue->is_private)->toBeTrue();
});
