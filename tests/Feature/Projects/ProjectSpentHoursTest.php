<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function projectSpentHoursMember(Project $project, array $permissions = ['view_time_entries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a project with view_time_entries shows its total logged hours', function () {
    $project = Project::factory()->create();
    $user = projectSpentHoursMember($project);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    TimeEntry::factory()->for($project)->for($issue)->create(['hours' => 2]);
    TimeEntry::factory()->for($project)->for($issue)->create(['hours' => 1.5]);

    $component = Livewire::actingAs($user)->test('projects.show', ['project' => $project]);

    expect($component->get('totalSpentHours'))->toBe(3.5);
    $component->assertSee('実績工数')->assertSee('3.5');
});

test('the spent hours block is hidden without view_time_entries', function () {
    $project = Project::factory()->create();
    $user = projectSpentHoursMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    TimeEntry::factory()->for($project)->for($issue)->create(['hours' => 2]);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->assertDontSee('実績工数');
});

test('the spent hours block is hidden when no time has been logged', function () {
    $project = Project::factory()->create();
    $user = projectSpentHoursMember($project);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->assertDontSee('実績工数');
});
