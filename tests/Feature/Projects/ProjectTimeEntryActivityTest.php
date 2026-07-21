<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function projectActivityManager(Project $project, array $permissions = ['edit_project', 'log_time', 'view_time_entries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a project has every global activity available by default', function () {
    $project = Project::factory()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Development']);

    expect($project->activities()->pluck('name'))->toContain('Development');
});

test('deactivating an activity for a project creates an override and removes it from that project only', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = projectActivityManager($projectA);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Design']);

    Livewire::actingAs($user)
        ->test('projects.activities', ['project' => $projectA])
        ->set("active.{$activity->id}", false)
        ->call('save');

    expect($projectA->activities()->pluck('name'))->not->toContain('Design')
        ->and($projectB->activities()->pluck('name'))->toContain('Design');

    $override = $projectA->timeEntryActivityOverrides()->where('parent_id', $activity->id)->first();
    expect($override)->not->toBeNull()
        ->and($override->active)->toBeFalse()
        ->and($override->name)->toBe('Design');
});

test('re-enabling a deactivated activity removes the override row', function () {
    $project = Project::factory()->create();
    $user = projectActivityManager($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    $component = Livewire::actingAs($user)->test('projects.activities', ['project' => $project]);
    $component->set("active.{$activity->id}", false)->call('save');
    expect($project->timeEntryActivityOverrides()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test('projects.activities', ['project' => $project])
        ->set("active.{$activity->id}", true)
        ->call('save');

    expect($project->timeEntryActivityOverrides()->count())->toBe(0)
        ->and($project->activities()->pluck('id'))->toContain($activity->id);
});

test('a user without edit_project cannot access or save the activities settings page', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_time_entries']])
    );
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('projects.activities', ['project' => $project])
        ->assertForbidden();
});

test('the time entry form only offers activities this project has not deactivated', function () {
    $project = Project::factory()->create();
    $user = projectActivityManager($project);
    $active = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Coding']);
    $deactivated = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Meetings']);

    Livewire::actingAs($user)
        ->test('projects.activities', ['project' => $project])
        ->set("active.{$deactivated->id}", false)
        ->call('save');

    $component = Livewire::actingAs($user)->test('time-entries.form', ['project' => $project]);

    expect($component->get('activities')->pluck('id'))
        ->toContain($active->id)
        ->not->toContain($deactivated->id);
});

test('logging time against a deactivated activity is rejected', function () {
    $project = Project::factory()->create();
    $user = projectActivityManager($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('projects.activities', ['project' => $project])
        ->set("active.{$activity->id}", false)
        ->call('save');

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)
        ->set('hours', '2')
        ->set('spent_on', now()->toDateString())
        ->call('save')
        ->assertHasErrors(['activity_id']);
});
