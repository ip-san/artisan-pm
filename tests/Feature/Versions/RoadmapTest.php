<?php

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

function roadmapMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function roadmapIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create(array_merge([
        'tracker_id' => Tracker::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ], $attributes));
}

test('a member with view_issues can see the roadmap', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project);
    Version::factory()->for($project)->create(['name' => 'Sprint 1']);

    Livewire::actingAs($user)
        ->test('versions.roadmap', ['project' => $project])
        ->assertOk()
        ->assertSee('Sprint 1');
});

test('a member without view_issues cannot see the roadmap', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project, []);

    Livewire::actingAs($user)
        ->test('versions.roadmap', ['project' => $project])
        ->assertForbidden();
});

test('a completed version is excluded from the roadmap', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project);
    Version::factory()->for($project)->create(['name' => 'Old Sprint', 'status' => 'closed']);
    Version::factory()->for($project)->create(['name' => 'Current Sprint']);

    $names = Livewire::actingAs($user)
        ->test('versions.roadmap', ['project' => $project])
        ->get('versions')
        ->pluck('name');

    expect($names)->toContain('Current Sprint')->not->toContain('Old Sprint');
});

test('versions are sorted by due date, soonest first, undated last', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project);
    $undated = Version::factory()->for($project)->create(['name' => 'No Due Date', 'due_date' => null]);
    $later = Version::factory()->for($project)->create(['name' => 'Later', 'due_date' => now()->addMonth()]);
    $soon = Version::factory()->for($project)->create(['name' => 'Soon', 'due_date' => now()->addWeek()]);

    $names = Livewire::actingAs($user)
        ->test('versions.roadmap', ['project' => $project])
        ->get('versions')
        ->pluck('name');

    expect($names->all())->toBe(['Soon', 'Later', 'No Due Date']);
});

test('issue counts and closed percent reflect the version\'s issues', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $openStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);

    roadmapIssue($project, ['status_id' => $openStatus->id, 'fixed_version_id' => $version->id]);
    roadmapIssue($project, ['status_id' => $closedStatus->id, 'fixed_version_id' => $version->id]);
    roadmapIssue($project, ['status_id' => $closedStatus->id, 'fixed_version_id' => $version->id]);

    $counts = $version->issueCounts();

    expect($counts)->toBe(['open' => 1, 'closed' => 2])
        ->and($version->closedPercent())->toBe(66.7);
});

test('completed percent weights open issues by estimated hours and treats closed as fully done', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $openStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);

    // Closed issue: 10h estimated, counts as 100% regardless of done_ratio.
    roadmapIssue($project, ['status_id' => $closedStatus->id, 'fixed_version_id' => $version->id, 'estimated_hours' => 10, 'done_ratio' => 40]);
    // Open issue: 10h estimated, 50% done.
    roadmapIssue($project, ['status_id' => $openStatus->id, 'fixed_version_id' => $version->id, 'estimated_hours' => 10, 'done_ratio' => 50]);

    // (10*100 + 10*50) / (10*2) = 75%
    expect($version->completedPercent())->toBe(75.0);
});

test('the roadmap excludes issues under a tracker with is_in_roadmap disabled', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project);
    $version = Version::factory()->for($project)->create();
    $status = IssueStatus::factory()->create(['is_closed' => false]);

    $roadmapTracker = Tracker::factory()->create(['is_in_roadmap' => true]);
    $excludedTracker = Tracker::factory()->create(['is_in_roadmap' => false]);

    roadmapIssue($project, ['tracker_id' => $roadmapTracker->id, 'status_id' => $status->id, 'fixed_version_id' => $version->id]);
    roadmapIssue($project, ['tracker_id' => $excludedTracker->id, 'status_id' => $status->id, 'fixed_version_id' => $version->id]);

    $component = Livewire::actingAs($user)
        ->test('versions.roadmap', ['project' => $project])
        ->assertSee('1件の課題');

    $roadmapTrackerIds = $component->get('roadmapTrackerIds');

    expect($roadmapTrackerIds)->toContain($roadmapTracker->id)->not->toContain($excludedTracker->id)
        ->and($version->issueCounts($roadmapTrackerIds))->toBe(['open' => 1, 'closed' => 0]);
});

test('a version with no issues reports zero for counts and percentages', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();

    expect($version->issueCounts())->toBe(['open' => 0, 'closed' => 0])
        ->and($version->closedPercent())->toBe(0.0)
        ->and($version->completedPercent())->toBe(0.0);
});
