<?php

use App\Enums\VersionSharing;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Laravel\Passport\Passport;

/**
 * A source project where the user may edit issues and a target project
 * where they may add them, both using the same tracker.
 *
 * @return array{source: Project, target: Project, tracker: Tracker, user: User, issue: Issue}
 */
function apiMoveSetup(array $sourcePermissions = ['view_issues', 'edit_issues'], array $targetPermissions = ['view_issues', 'add_issues']): array
{
    $tracker = Tracker::factory()->create();
    $source = Project::factory()->create(['identifier' => 'move-source']);
    $target = Project::factory()->create(['identifier' => 'move-target']);
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $sourcePermissions]));

    if ($targetPermissions !== []) {
        Member::factory()->for($target)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $targetPermissions]));
    }

    $issue = Issue::factory()->for($source)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    return compact('source', 'target', 'tracker', 'user', 'issue');
}

test('project_id on PUT moves the issue, by id or identifier, in one journal', function () {
    $s = apiMoveSetup();
    $category = IssueCategory::factory()->for($s['source'])->create(['name' => 'UI']);
    $targetCategory = IssueCategory::factory()->for($s['target'])->create(['name' => 'UI']);
    $shared = Version::factory()->for($s['source'])->create(['sharing' => VersionSharing::System]);
    $s['issue']->update(['category_id' => $category->id, 'fixed_version_id' => $shared->id]);

    Passport::actingAs($s['user']);

    // A client sending back the whole issue: its old category is discarded.
    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => 'move-target', 'category_id' => $category->id, 'subject' => 'Moved'])
        ->assertOk()
        ->assertJsonPath('data.project_id', $s['target']->id);

    $issue = $s['issue']->fresh();
    $journal = $issue->journals()->sole();

    expect($issue->project_id)->toBe($s['target']->id)
        ->and($issue->subject)->toBe('Moved')
        ->and($issue->category_id)->toBe($targetCategory->id)
        ->and($issue->fixed_version_id)->toBe($shared->id)
        ->and($journal->details()->where('prop_key', 'project_id')->exists())->toBeTrue()
        ->and($journal->details()->where('prop_key', 'subject')->exists())->toBeTrue();
});

test('moving needs edit_issues on the issue, not a move permission', function () {
    $s = apiMoveSetup(['view_issues', 'add_issue_notes']);

    Passport::actingAs($s['user']);

    // A notes-only caller's project_id is ignored, as its other fields are.
    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id, 'notes' => 'Comment'])->assertOk();

    expect($s['issue']->fresh()->project_id)->toBe($s['source']->id);
});

test('a target without add_issues is rejected', function () {
    $s = apiMoveSetup(targetPermissions: ['view_issues']);

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['project_id']);

    expect($s['issue']->fresh()->project_id)->toBe($s['source']->id);
});

test('a private project the caller cannot see is rejected like any other', function () {
    $s = apiMoveSetup(targetPermissions: []);
    $s['target']->update(['is_public' => false]);

    Passport::actingAs($s['user']);

    $hidden = $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['project_id']);
    $missing = $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => 999999])
        ->assertUnprocessable();

    expect($hidden->json('errors.project_id'))->toBe($missing->json('errors.project_id'))
        ->and($s['issue']->fresh()->project_id)->toBe($s['source']->id);
});

test('a tracker the caller may not add in the target is rejected', function () {
    $s = apiMoveSetup(targetPermissions: []);
    $other = Tracker::factory()->create();
    $s['target']->trackers()->attach($other);
    Member::factory()->for($s['target'])->for($s['user'])->create()->roles()->attach(
        Role::factory()->limitedToTrackers('add_issues', [$s['tracker']->id])->create(['permissions' => ['view_issues', 'add_issues']])
    );

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id, 'tracker_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['tracker_id']);

    expect($s['issue']->fresh()->project_id)->toBe($s['source']->id);
});

test('a tracker the target does not use gives way to one the caller may add there', function () {
    $s = apiMoveSetup();
    $s['target']->trackers()->detach($s['tracker']);
    $targetTracker = Tracker::factory()->create();
    $s['target']->trackers()->attach($targetTracker);

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])->assertOk();

    expect($s['issue']->fresh()->project_id)->toBe($s['target']->id)
        ->and($s['issue']->fresh()->tracker_id)->toBe($targetTracker->id);
});

test('subtasks, time entries and relations follow Redmine\'s after_project_change', function () {
    $s = apiMoveSetup();
    $child = Issue::factory()->for($s['source'])->create(['tracker_id' => $s['tracker']->id, 'parent_id' => $s['issue']->id]);
    $grandchild = Issue::factory()->for($s['source'])->create(['tracker_id' => $s['tracker']->id, 'parent_id' => $child->id]);
    $entry = TimeEntry::factory()->create(['project_id' => $s['source']->id, 'issue_id' => $s['issue']->id]);
    $childEntry = TimeEntry::factory()->create(['project_id' => $s['source']->id, 'issue_id' => $child->id]);
    $other = Issue::factory()->for($s['source'])->create(['tracker_id' => $s['tracker']->id]);
    IssueRelation::create(['issue_from_id' => $s['issue']->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])->assertOk();

    expect($child->fresh()->project_id)->toBe($s['target']->id)
        ->and($child->fresh()->parent_id)->toBe($s['issue']->id)
        ->and($grandchild->fresh()->project_id)->toBe($s['target']->id)
        ->and($entry->fresh()->project_id)->toBe($s['target']->id)
        ->and($childEntry->fresh()->project_id)->toBe($s['target']->id)
        ->and(IssueRelation::query()->count())->toBe(0);
});

test('relations are kept when cross-project relations are allowed', function () {
    Setting::set('cross_project_issue_relations', true);
    $s = apiMoveSetup();
    $other = Issue::factory()->for($s['source'])->create(['tracker_id' => $s['tracker']->id]);
    IssueRelation::create(['issue_from_id' => $s['issue']->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])->assertOk();

    expect(IssueRelation::query()->count())->toBe(1);
});

test('a subtask whose tracker the target does not use stops the whole move', function () {
    $s = apiMoveSetup();
    $childTracker = Tracker::factory()->create();
    $s['source']->trackers()->attach($childTracker);
    $child = Issue::factory()->for($s['source'])->create(['tracker_id' => $childTracker->id, 'parent_id' => $s['issue']->id]);

    Passport::actingAs($s['user']);

    $this->putJson("/api/v1/issues/{$s['issue']->id}", ['project_id' => $s['target']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['project_id']);

    expect($s['issue']->fresh()->project_id)->toBe($s['source']->id)
        ->and($child->fresh()->project_id)->toBe($s['source']->id);
});
