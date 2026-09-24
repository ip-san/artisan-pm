<?php

use App\Enums\ProjectStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
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
    // The default selection is the project's trackers shown in the roadmap.
    $project->trackers()->attach([$roadmapTracker->id, $excludedTracker->id]);

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

test('the roadmap\'s issue count links deep-link into a version- and status-filtered issue list', function () {
    $project = Project::factory()->create();
    $user = roadmapMember($project);
    $version = Version::factory()->for($project)->create();
    $otherVersion = Version::factory()->for($project)->create();
    $openStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);

    $openIssue = roadmapIssue($project, ['status_id' => $openStatus->id, 'fixed_version_id' => $version->id]);
    $closedIssue = roadmapIssue($project, ['status_id' => $closedStatus->id, 'fixed_version_id' => $version->id]);
    $otherVersionIssue = roadmapIssue($project, ['status_id' => $openStatus->id, 'fixed_version_id' => $otherVersion->id]);

    // Reflects the exact query shape roadmap.blade.php's issuesUrl() builds
    // (statusFilter + the filter-builder's own activeFilterKeys/
    // filterOperators/filterValues), asserted end-to-end against the issue
    // list rather than asserting on the href string itself.
    $query = [
        'statusFilter' => 'all',
        'activeFilterKeys' => ['fixed_version_id'],
        'filterOperators' => ['fixed_version_id' => '='],
        'filterValues' => ['fixed_version_id' => [$version->id]],
    ];

    $totalList = Livewire::actingAs($user)
        ->withQueryParams($query)
        ->test('issues.index', ['project' => $project])
        ->get('issues')
        ->pluck('id');

    expect($totalList)->toContain($openIssue->id, $closedIssue->id)
        ->not->toContain($otherVersionIssue->id);
});

/**
 * A3-14: Redmine's roadmap with_subprojects (rolled_up_versions.visible).
 *
 * @return array{parent: Project, child: Project, hidden: Project, archived: Project, user: User}
 */
function roadmapSubprojectTree(): array
{
    $parent = Project::factory()->create(['name' => 'Parent project']);
    $child = Project::factory()->create(['name' => 'Child project', 'parent_id' => $parent->id]);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden project', 'parent_id' => $parent->id]);
    $archived = Project::factory()->create(['name' => 'Archived project', 'parent_id' => $parent->id]);
    $user = roadmapMember($parent);
    Member::factory()->for($child)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    Version::factory()->for($parent)->create(['name' => 'Parent release', 'due_date' => now()->addDays(20)]);
    Version::factory()->for($child)->create(['name' => 'Child release', 'due_date' => now()->addDays(10)]);
    Version::factory()->for($hidden)->create(['name' => 'Hidden release']);
    Version::factory()->for($archived)->create(['name' => 'Archived release']);
    $archived->status = ProjectStatus::Archived;
    $archived->save();

    return ['parent' => $parent->fresh(), 'child' => $child->fresh(), 'hidden' => $hidden, 'archived' => $archived, 'user' => $user];
}

test('the roadmap lists the visible subprojects\' versions with the subprojects switch', function () {
    ['parent' => $parent, 'user' => $user] = roadmapSubprojectTree();

    $roadmap = Livewire::actingAs($user)->test('versions.roadmap', ['project' => $parent]);
    expect($roadmap->get('versions')->pluck('name')->all())->toBe(['Parent release']);
    $roadmap->assertSee('サブプロジェクト');

    $roadmap->call('toggleSubprojects');
    expect($roadmap->get('withSubprojects'))->toBe('1')
        ->and($roadmap->get('versions')->pluck('name')->all())->toBe(['Child release', 'Parent release']);
    $roadmap->assertSee('Child project - Child release')
        ->assertDontSee('Hidden release')
        ->assertDontSee('Archived release');
});

test('the roadmap follows display_subprojects_issues unless with_subprojects is given', function () {
    ['parent' => $parent, 'user' => $user] = roadmapSubprojectTree();
    Setting::set('display_subprojects_issues', true);

    expect(Livewire::actingAs($user)->test('versions.roadmap', ['project' => $parent])->get('versions')->pluck('name')->all())
        ->toBe(['Child release', 'Parent release']);

    expect(Livewire::withQueryParams(['with_subprojects' => '0'])->actingAs($user)->test('versions.roadmap', ['project' => $parent])->get('versions')->pluck('name')->all())
        ->toBe(['Parent release']);
});

test('a subproject\'s versions stay off the roadmap for a viewer without view_issues there', function () {
    ['parent' => $parent] = roadmapSubprojectTree();
    $outsider = roadmapMember($parent);

    $names = Livewire::withQueryParams(['with_subprojects' => '1'])->actingAs($outsider)
        ->test('versions.roadmap', ['project' => $parent])->get('versions')->pluck('name')->all();

    expect($names)->not->toContain('Hidden release')->not->toContain('Archived release');
});

test('a leaf project\'s roadmap has no subprojects switch', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(roadmapMember($project))->test('versions.roadmap', ['project' => $project])
        ->assertDontSee('サブプロジェクト');
});

/**
 * @return array{project: Project, user: User, bug: Tracker, support: Tracker, open: IssueStatus, closed: IssueStatus}
 */
function roadmapTrackerSetup(): array
{
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create(['name' => 'Bug', 'is_in_roadmap' => true, 'position' => 1]);
    $support = Tracker::factory()->create(['name' => 'Support', 'is_in_roadmap' => false, 'position' => 2]);
    $project->trackers()->attach([$bug->id, $support->id]);

    return [
        'project' => $project,
        'user' => roadmapMember($project),
        'bug' => $bug,
        'support' => $support,
        'open' => IssueStatus::factory()->create(['is_closed' => false]),
        'closed' => IssueStatus::factory()->create(['is_closed' => true]),
    ];
}

test('completed versions are listed in the sidebar and shown with the completed switch', function () {
    ['project' => $project, 'user' => $user] = roadmapTrackerSetup();
    Version::factory()->for($project)->create(['name' => 'Shipped', 'status' => 'closed']);
    Version::factory()->for($project)->create(['name' => 'Next']);

    $roadmap = Livewire::actingAs($user)->test('versions.roadmap', ['project' => $project]);
    expect($roadmap->get('versions')->pluck('name')->all())->toBe(['Next'])
        ->and($roadmap->get('completedVersions')->pluck('name')->all())->toBe(['Shipped']);
    $roadmap->assertSee('完了したバージョン');

    $roadmap->call('toggleCompleted');
    expect($roadmap->get('showCompleted'))->toBeTrue()
        ->and($roadmap->get('versions')->pluck('name')->sort()->values()->all())->toBe(['Next', 'Shipped'])
        ->and($roadmap->get('completedVersions'))->toBeEmpty();

    expect(Livewire::withQueryParams(['completed' => '1'])->actingAs($user)->test('versions.roadmap', ['project' => $project])->get('versions'))->toHaveCount(2);
});

test('each version lists its visible issues of the selected trackers, and the tracker selection changes them', function () {
    ['project' => $project, 'user' => $user, 'bug' => $bug, 'support' => $support, 'open' => $open, 'closed' => $closed] = roadmapTrackerSetup();
    $version = Version::factory()->for($project)->create();
    $bugIssue = roadmapIssue($project, ['tracker_id' => $bug->id, 'status_id' => $open->id, 'fixed_version_id' => $version->id, 'subject' => 'Crash on save']);
    $closedBug = roadmapIssue($project, ['tracker_id' => $bug->id, 'status_id' => $closed->id, 'fixed_version_id' => $version->id, 'subject' => 'Old crash']);
    $supportIssue = roadmapIssue($project, ['tracker_id' => $support->id, 'status_id' => $open->id, 'fixed_version_id' => $version->id, 'subject' => 'Help request']);

    $roadmap = Livewire::actingAs($user)->test('versions.roadmap', ['project' => $project])
        ->assertSee('Crash on save')
        ->assertSee('Old crash')
        ->assertDontSee('Help request')
        ->assertSee('2件の課題');
    expect($roadmap->get('issuesByVersion')->get($version->id)->pluck('id')->all())->toBe([$bugIssue->id, $closedBug->id]);

    $roadmap->call('toggleTracker', $support->id)
        ->assertSee('Help request')
        ->assertSee('3件の課題');
    expect($roadmap->get('trackerIds'))->toBe([$bug->id, $support->id]);

    $roadmap->call('toggleTracker', $bug->id)
        ->assertDontSee('Crash on save')
        ->assertSee('Help request');

    Livewire::withQueryParams(['tracker_ids' => [(string) $support->id]])->actingAs($user)->test('versions.roadmap', ['project' => $project])
        ->assertSee('Help request')
        ->assertDontSee('Crash on save');
});

test('the version issue list leaves out issues the viewer cannot see', function () {
    ['project' => $project, 'bug' => $bug, 'open' => $open] = roadmapTrackerSetup();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'default'])
    );
    $version = Version::factory()->for($project)->create();
    roadmapIssue($project, ['tracker_id' => $bug->id, 'status_id' => $open->id, 'fixed_version_id' => $version->id, 'subject' => 'Public bug']);
    roadmapIssue($project, ['tracker_id' => $bug->id, 'status_id' => $open->id, 'fixed_version_id' => $version->id, 'subject' => 'Secret bug', 'is_private' => true]);

    Livewire::actingAs($viewer)->test('versions.roadmap', ['project' => $project])
        ->assertSee('Public bug')
        ->assertDontSee('Secret bug');
});

test('a version shared from another project appears only while this project\'s issues target it', function () {
    ['project' => $project, 'user' => $user, 'bug' => $bug, 'open' => $open] = roadmapTrackerSetup();
    $other = Project::factory()->create(['name' => 'Platform']);
    $shared = Version::factory()->for($other)->create(['name' => 'Platform 2.0', 'sharing' => 'system']);
    Version::factory()->for($other)->create(['name' => 'Unshared', 'sharing' => 'none']);
    $otherTracker = Tracker::factory()->create();
    roadmapIssue($other, ['tracker_id' => $otherTracker->id, 'status_id' => $open->id, 'fixed_version_id' => $shared->id, 'subject' => 'Platform task']);

    $roadmap = Livewire::actingAs($user)->test('versions.roadmap', ['project' => $project]);
    expect($roadmap->get('versions')->pluck('name')->all())->not->toContain('Platform 2.0')->not->toContain('Unshared');

    roadmapIssue($project, ['tracker_id' => $bug->id, 'status_id' => $open->id, 'fixed_version_id' => $shared->id, 'subject' => 'Adopt platform']);

    Livewire::actingAs($user)->test('versions.roadmap', ['project' => $project])
        ->assertSee('Platform - Platform 2.0')
        ->assertSee('Adopt platform')
        ->assertDontSee('Platform task')
        ->assertDontSee('Unshared');
});

test('subproject issues are listed only with the subprojects switch', function () {
    ['parent' => $parent, 'child' => $child, 'user' => $user] = roadmapSubprojectTree();
    $tracker = Tracker::factory()->create(['is_in_roadmap' => true]);
    $parent->trackers()->attach($tracker);
    $child->trackers()->attach($tracker);
    $version = Version::query()->where('name', 'Parent release')->sole();
    $version->update(['sharing' => 'descendants']);
    roadmapIssue($child, ['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'fixed_version_id' => $version->id, 'subject' => 'Child work']);

    Livewire::withQueryParams(['with_subprojects' => '0'])->actingAs($user)->test('versions.roadmap', ['project' => $parent])
        ->assertDontSee('Child work');

    Livewire::withQueryParams(['with_subprojects' => '1'])->actingAs($user)->test('versions.roadmap', ['project' => $parent])
        ->assertSee('Child work')
        ->assertSee('Child project - ');
});
