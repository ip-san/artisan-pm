<?php

use App\Enums\VersionSharing;
use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function moveTestMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function moveTestIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('a user with edit_issues (no move permission, as in Redmine) can move an issue to a project they can add issues to', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = moveTestMember($source, ['view_issues', 'edit_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $issue = moveTestIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $targetTracker->id)
        ->call('moveIssue')
        ->assertRedirect(route('issues.show', [$target, $issue]));

    expect($issue->fresh()->project_id)->toBe($target->id)
        ->and($issue->fresh()->tracker_id)->toBe($targetTracker->id);
});

test('moving an issue resets its category, fixed version and parent, and takes its subtasks along', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    // The subtask keeps its tracker (Redmine's after_project_change), so the target must use it.
    $target->trackers()->attach([$targetTracker->id, $sourceTracker->id]);

    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $category = IssueCategory::factory()->for($source)->create();
    $version = Version::factory()->for($source)->create();
    $parent = moveTestIssue($source, $sourceTracker);
    $issue = moveTestIssue($source, $sourceTracker, [
        'category_id' => $category->id,
        'fixed_version_id' => $version->id,
        'parent_id' => $parent->id,
    ]);
    $child = moveTestIssue($source, $sourceTracker, ['parent_id' => $issue->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $targetTracker->id)
        ->call('moveIssue');

    $issue->refresh();
    expect($issue->category_id)->toBeNull()
        ->and($issue->fixed_version_id)->toBeNull()
        ->and($issue->parent_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($issue->id)
        ->and($child->fresh()->project_id)->toBe($target->id)
        ->and($child->fresh()->tracker_id)->toBe($sourceTracker->id);
});

test('moving an issue clears the assignee if they are not a member of the target project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $assignee = moveTestMember($source, ['view_issues']);
    $issue = moveTestIssue($source, $sourceTracker, ['assigned_to_id' => $assignee->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $targetTracker->id)
        ->call('moveIssue');

    expect($issue->fresh()->assigned_to_id)->toBeNull();
});

test('moving an issue keeps the assignee if they are also a member of the target project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $assignee = User::factory()->create();
    Member::factory()->for($source)->for($assignee)->create();
    Member::factory()->for($target)->for($assignee)->create();

    $issue = moveTestIssue($source, $sourceTracker, ['assigned_to_id' => $assignee->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $targetTracker->id)
        ->call('moveIssue');

    expect($issue->fresh()->assigned_to_id)->toBe($assignee->id);
});

test('a user without edit_issues cannot move an issue', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);

    $user = moveTestMember($source, ['view_issues']);
    $issue = moveTestIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->call('moveIssue')
        ->assertForbidden();

    expect($issue->fresh()->project_id)->toBe($source->id);
});

test('a project the user cannot add issues to is not offered as a move target', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);

    $user = moveTestMember($source, ['view_issues', 'edit_issues']);
    $issue = moveTestIssue($source, $sourceTracker);

    $component = Livewire::actingAs($user)->test('issues.show', ['project' => $source, 'issue' => $issue]);

    expect($component->get('moveTargetProjects')->pluck('id'))->not->toContain($target->id);
});

test('the move records a journal entry with the project change', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']])
    );
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $issue = moveTestIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $targetTracker->id)
        ->call('moveIssue');

    $journal = $issue->fresh()->journals()->latest()->firstOrFail();
    expect($journal->details()->where('prop_key', 'project_id')->exists())->toBeTrue();
});

test('moving an issue keeps a shared version and takes the same-named category, as Redmine does', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = moveTestMember($source, ['view_issues', 'edit_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $category = IssueCategory::factory()->for($source)->create(['name' => 'UI']);
    $targetCategory = IssueCategory::factory()->for($target)->create(['name' => 'UI']);
    $shared = Version::factory()->for($source)->create(['sharing' => VersionSharing::System, 'status' => VersionStatus::Closed]);
    $issue = moveTestIssue($source, $tracker, ['category_id' => $category->id, 'fixed_version_id' => $shared->id]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $source, 'issue' => $issue])
        ->set('moveToProjectId', $target->id)
        ->set('moveToTrackerId', $tracker->id)
        ->call('moveIssue');

    expect($issue->fresh()->category_id)->toBe($targetCategory->id)
        ->and($issue->fresh()->fixed_version_id)->toBe($shared->id);
});

test('a moved issue whose version the target does not share gets no version, not the target default', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = moveTestMember($source, ['view_issues', 'edit_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $default = Version::factory()->for($target)->create();
    $target->update(['default_version_id' => $default->id]);
    $local = Version::factory()->for($source)->create(['sharing' => VersionSharing::None]);
    $withVersion = moveTestIssue($source, $tracker, ['fixed_version_id' => $local->id]);
    $withoutVersion = moveTestIssue($source, $tracker);

    foreach ([$withVersion, $withoutVersion] as $issue) {
        Livewire::actingAs($user)
            ->test('issues.show', ['project' => $source, 'issue' => $issue])
            ->set('moveToProjectId', $target->id)
            ->set('moveToTrackerId', $tracker->id)
            ->call('moveIssue');
    }

    expect($withVersion->fresh()->fixed_version_id)->toBeNull()
        ->and($withoutVersion->fresh()->fixed_version_id)->toBeNull()
        ->and($withVersion->fresh()->project_id)->toBe($target->id);
});
