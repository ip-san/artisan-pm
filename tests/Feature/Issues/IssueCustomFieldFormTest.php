<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('creating an issue persists its custom field values', function () {
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create(['name' => 'Client email']);
    $field->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'New issue with custom field')
        ->set("customFieldValues.{$field->id}", 'client@example.com')
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'New issue with custom field')->firstOrFail();

    expect($issue->customValue($field))->toBe('client@example.com');
});

test('a required custom field blocks submission when left blank', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->required()->create();
    $field->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Missing required custom field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing an issue preloads and updates its existing custom field value', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();

    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Int->value]);
    $field->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 10]);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue]);
    expect($component->get('customFieldValues')[$field->id])->toBe(10);

    $component->set("customFieldValues.{$field->id}", 25)->call('save')->assertRedirect();

    expect($issue->fresh()->customValue($field))->toBe(25);
});

test('a custom field from an unrelated tracker is neither rendered nor saved', function () {
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $otherTracker = Tracker::factory()->create();
    $project->trackers()->attach([$tracker->id, $otherTracker->id]);

    $field = CustomField::factory()->create(['name' => 'Unrelated field']);
    $field->trackers()->attach($otherTracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Issue on the other tracker')
        ->assertDontSee('Unrelated field')
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Issue on the other tracker')->firstOrFail();

    expect($issue->customFieldValues()->count())->toBe(0);
});
