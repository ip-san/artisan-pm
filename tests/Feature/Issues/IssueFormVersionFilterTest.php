<?php

use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function versionFilterMember(Project $project, array $permissions = ['view_issues', 'add_issues', 'edit_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function versionFilterIssue(Project $project, Tracker $tracker, ?int $fixedVersionId = null): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'fixed_version_id' => $fixedVersionId,
    ]);
}

test('a locked version is not offered when creating a new issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $open = Version::factory()->for($project)->create(['status' => VersionStatus::Open->value]);
    $locked = Version::factory()->for($project)->create(['status' => VersionStatus::Locked->value]);
    $user = versionFilterMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('projectVersions')->pluck('id'))
        ->toContain($open->id)
        ->not->toContain($locked->id);
});

test('an issue already assigned to a locked version keeps it selectable', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $locked = Version::factory()->for($project)->create(['status' => VersionStatus::Locked->value]);
    $issue = versionFilterIssue($project, $tracker, $locked->id);
    $user = versionFilterMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue]);

    expect($component->get('projectVersions')->pluck('id'))->toContain($locked->id);
});

test('submitting a locked version that was never assigned to the issue is rejected', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $locked = Version::factory()->for($project)->create(['status' => VersionStatus::Locked->value]);
    $issue = versionFilterIssue($project, $tracker);
    $user = versionFilterMember($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('fixed_version_id', $locked->id)
        ->call('save')
        ->assertHasErrors(['fixed_version_id']);

    expect($issue->fresh()->fixed_version_id)->toBeNull();
});
