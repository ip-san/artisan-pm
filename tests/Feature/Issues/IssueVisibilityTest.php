<?php

use App\Enums\IssueVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function visibilityIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('a member with own-only visibility cannot view an issue authored and assigned to someone else', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::Own->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $other = User::factory()->create();
    $issue = visibilityIssue($project, ['author_id' => $other->id, 'assigned_to_id' => $other->id]);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertForbidden();
});

test('a member with own-only visibility can view an issue they authored or are assigned to', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::Own->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $authored = visibilityIssue($project, ['author_id' => $user->id]);
    $assigned = visibilityIssue($project, ['assigned_to_id' => $user->id]);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $authored])->assertOk();
    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $assigned])->assertOk();
});

test('the issue list only shows own issues to an own-only visibility member', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::Own->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $mine = visibilityIssue($project, ['tracker_id' => $tracker->id, 'author_id' => $user->id]);
    $notMine = visibilityIssue($project, ['tracker_id' => $tracker->id]);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');

    $ids = $component->instance()->issues->pluck('id');

    expect($ids)->toContain($mine->id)->not->toContain($notMine->id);
});

test('a member with all-visibility sees every issue in the project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => IssueVisibility::All->value]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $other = User::factory()->create();
    $issue = visibilityIssue($project, ['tracker_id' => $tracker->id, 'author_id' => $other->id, 'assigned_to_id' => $other->id]);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertOk();
});
