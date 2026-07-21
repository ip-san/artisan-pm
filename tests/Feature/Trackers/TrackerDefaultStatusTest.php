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

test('an admin can set a tracker default status', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($admin)
        ->test('trackers.form', ['tracker' => $tracker])
        ->set('default_status_id', $status->id)
        ->call('save');

    expect($tracker->fresh()->default_status_id)->toBe($status->id);
});

test('a new issue defaults to its tracker default status when one is set', function () {
    Enumeration::factory()->create(['is_default' => true]);
    $globalFirst = IssueStatus::factory()->create(['name' => 'Global first']);
    $trackerDefault = IssueStatus::factory()->create(['name' => 'Tracker default']);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['default_status_id' => $trackerDefault->id]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('status_id'))->toBe($trackerDefault->id);
});

test('a new issue falls back to the global first status when the tracker has no default', function () {
    Enumeration::factory()->create(['is_default' => true]);
    $globalFirst = IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['default_status_id' => null]);
    $project->trackers()->attach($tracker);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('status_id'))->toBe($globalFirst->id);
});

test('switching tracker on a new issue re-derives the default status', function () {
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();
    $trackerBStatus = IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $trackerA = Tracker::factory()->create();
    $trackerB = Tracker::factory()->create(['default_status_id' => $trackerBStatus->id]);
    $project->trackers()->attach([$trackerA->id, $trackerB->id]);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $trackerB->id)
        ->assertSet('status_id', $trackerBStatus->id);
});

test('changing tracker while editing an existing issue does not change its status', function () {
    $project = Project::factory()->create();
    $trackerA = Tracker::factory()->create();
    $trackerB = Tracker::factory()->create(['default_status_id' => IssueStatus::factory()->create()->id]);
    $project->trackers()->attach([$trackerA->id, $trackerB->id]);
    $originalStatus = IssueStatus::factory()->create();

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $trackerA->id,
        'status_id' => $originalStatus->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('tracker_id', $trackerB->id)
        ->assertSet('status_id', $originalStatus->id);
});
