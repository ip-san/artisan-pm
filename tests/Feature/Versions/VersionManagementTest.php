<?php

use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function versionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a member with manage_versions can create a version', function () {
    $project = Project::factory()->create();
    $user = versionMember($project, ['manage_versions']);

    Livewire::actingAs($user)
        ->test('versions.form', ['project' => $project])
        ->set('name', '1.0.0')
        ->set('status', 'locked')
        ->set('due_date', '2026-12-31')
        ->call('save')
        ->assertRedirect();

    $version = Version::where('name', '1.0.0')->firstOrFail();

    expect($version->project_id)->toBe($project->id)
        ->and($version->status)->toBe(VersionStatus::Locked)
        ->and($version->due_date->toDateString())->toBe('2026-12-31');
});

test('a member without manage_versions cannot open the version form', function () {
    $project = Project::factory()->create();
    $user = versionMember($project, ['view_issues']);

    Livewire::actingAs($user)->test('versions.form', ['project' => $project])->assertForbidden();
});

test('a member can edit an existing version', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['name' => 'Old name']);
    $user = versionMember($project, ['manage_versions']);

    Livewire::actingAs($user)
        ->test('versions.form', ['project' => $project, 'version' => $version])
        ->set('name', 'New name')
        ->call('save')
        ->assertRedirect();

    expect($version->fresh()->name)->toBe('New name');
});

test('a version in use by an issue cannot be deleted', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'fixed_version_id' => $version->id,
    ]);
    $user = versionMember($project, ['manage_versions']);

    Livewire::actingAs($user)
        ->test('versions.index', ['project' => $project])
        ->call('delete', $version->id);

    expect(Version::find($version->id))->not->toBeNull();
});

test('an unused version can be deleted', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $user = versionMember($project, ['manage_versions']);

    Livewire::actingAs($user)
        ->test('versions.index', ['project' => $project])
        ->call('delete', $version->id);

    expect(Version::find($version->id))->toBeNull();
});

test('version hour totals only count leaf issues for estimated hours but all issues for spent hours', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();

    $parent = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'fixed_version_id' => $version->id, 'estimated_hours' => 10, 'done_ratio' => 0,
    ]);
    $child = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'fixed_version_id' => $version->id, 'parent_id' => $parent->id, 'estimated_hours' => 4, 'done_ratio' => 50,
    ]);

    TimeEntry::factory()->for($project)->for($parent)->create(['hours' => 1]);
    TimeEntry::factory()->for($project)->for($child)->create(['hours' => 2]);

    // parent has a child, so its own estimated_hours (10) is excluded from
    // the version total to avoid double counting; only the leaf child's 4
    // hours count, at 50% remaining = 2.
    expect($version->estimatedHours())->toBe(4.0)
        ->and($version->estimatedRemainingHours())->toBe(2.0)
        ->and($version->spentHours())->toBe(3.0);
});

test('a version with a past due date and no open issues is completed', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['due_date' => now()->subDay()->toDateString()]);
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->closed()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'fixed_version_id' => $version->id,
    ]);

    expect($version->isCompleted())->toBeTrue();
});

test('a version with a past due date but an open issue is not completed', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['due_date' => now()->subDay()->toDateString()]);
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'fixed_version_id' => $version->id,
    ]);

    expect($version->isCompleted())->toBeFalse();
});

test('a version with no due date is never auto-completed', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['due_date' => null]);

    expect($version->isCompleted())->toBeFalse();
});

test('closing completed versions leaves an incomplete version untouched', function () {
    $project = Project::factory()->create();
    $incomplete = Version::factory()->for($project)->create(['due_date' => now()->addWeek()->toDateString()]);

    $project->closeCompletedVersions();

    expect($incomplete->fresh()->status)->toBe(VersionStatus::Open);
});

test('the close-completed-versions button closes a completed version and leaves others alone', function () {
    $project = Project::factory()->create();
    $completed = Version::factory()->for($project)->create(['status' => VersionStatus::Open->value, 'due_date' => now()->subDay()->toDateString()]);
    $stillOpen = Version::factory()->for($project)->create(['status' => VersionStatus::Open->value, 'due_date' => now()->addWeek()->toDateString()]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('versions.index', ['project' => $project])->call('closeCompleted');

    expect($completed->fresh()->status)->toBe(VersionStatus::Closed)
        ->and($stillOpen->fresh()->status)->toBe(VersionStatus::Open);
});
