<?php

use App\Enums\UserStatus;
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
use App\Services\IssueService;
use Livewire\Livewire;

/**
 * @return array{source: Project, target: Project, tracker: Tracker, actor: User}
 */
function subtaskCopySetup(): array
{
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);
    $actor = User::factory()->admin()->create();

    return compact('source', 'target', 'tracker', 'actor');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function subtaskCopyIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('copying with subtasks duplicates the whole tree under the copied parent', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = subtaskCopySetup();
    $root = subtaskCopyIssue($source, $tracker, ['subject' => 'root']);
    $child = subtaskCopyIssue($source, $tracker, ['subject' => 'child', 'parent_id' => $root->id]);
    subtaskCopyIssue($source, $tracker, ['subject' => 'grandchild', 'parent_id' => $child->id]);
    subtaskCopyIssue($source, $tracker, ['subject' => 'sibling', 'parent_id' => $root->id]);

    $copy = app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);

    $copies = Issue::query()->where('project_id', $target->id)->get()->keyBy('subject');
    expect($copies)->toHaveCount(4)
        ->and($copies['root']->id)->toBe($copy->id)
        ->and($copies['child']->parent_id)->toBe($copy->id)
        ->and($copies['sibling']->parent_id)->toBe($copy->id)
        ->and($copies['grandchild']->parent_id)->toBe($copies['child']->id);
    // The originals are untouched.
    expect($child->fresh()->parent_id)->toBe($root->id);
});

test('subtasks are not copied unless asked, and never get a copied_to relation', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = subtaskCopySetup();
    $root = subtaskCopyIssue($source, $tracker);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id]);

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor);
    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(1);

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);
    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(3)
        ->and($root->relationsFrom()->count())->toBe(2);
});

test('a subtask whose tracker the target project does not use is skipped with its subtree', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = subtaskCopySetup();
    $foreignTracker = Tracker::factory()->create();
    $source->trackers()->attach($foreignTracker);
    $root = subtaskCopyIssue($source, $tracker);
    $skipped = subtaskCopyIssue($source, $foreignTracker, ['parent_id' => $root->id, 'subject' => 'skipped']);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $skipped->id, 'subject' => 'orphaned']);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'kept']);

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);

    expect(Issue::query()->where('project_id', $target->id)->pluck('subject')->sort()->values()->all())->toBe(collect([$root->subject, 'kept'])->sort()->values()->all());
});

test('a subtask keeps its version only when it is open and reachable, and its assignee only while an active member', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = subtaskCopySetup();
    $root = subtaskCopyIssue($source, $tracker);
    $ownVersion = Version::factory()->for($source)->create(['status' => VersionStatus::Open]);
    $member = User::factory()->create();
    Member::factory()->for($target)->for($member)->create();
    $stranger = User::factory()->create();
    $locked = User::factory()->create(['status' => UserStatus::Locked]);
    Member::factory()->for($target)->for($locked)->create();

    $a = subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'a', 'fixed_version_id' => $ownVersion->id, 'assigned_to_id' => $member->id]);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'b', 'assigned_to_id' => $stranger->id]);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'c', 'assigned_to_id' => $locked->id]);

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);
    $copies = Issue::query()->where('project_id', $target->id)->get()->keyBy('subject');

    expect($copies['a']->assigned_to_id)->toBe($member->id)
        ->and($copies['a']->fixed_version_id)->toBeNull()
        ->and($copies['b']->assigned_to_id)->toBeNull()
        ->and($copies['c']->assigned_to_id)->toBeNull();

    // A version shared system-wide and open survives.
    $shared = Version::factory()->for($source)->create(['status' => VersionStatus::Open, 'sharing' => 'system']);
    $a->update(['fixed_version_id' => $shared->id]);
    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);
    expect(Issue::query()->where('project_id', $target->id)->where('subject', 'a')->latest('id')->first()->fixed_version_id)->toBe($shared->id);
});

test('a subtask the actor cannot see is not copied', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker] = subtaskCopySetup();
    $actor = User::factory()->create();
    Member::factory()->for($source)->for($actor)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'copy_issues'], 'issues_visibility' => 'default']));
    $root = subtaskCopyIssue($source, $tracker);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'public']);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id, 'subject' => 'secret', 'is_private' => true, 'author_id' => User::factory()->create()->id]);

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);

    expect(Issue::query()->where('project_id', $target->id)->pluck('subject')->all())->toContain('public')->not->toContain('secret');
});

test('the bulk copy form copies subtasks by default and lets you turn it off', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker] = subtaskCopySetup();
    $user = User::factory()->create();
    Member::factory()->for($source)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'copy_issues']]));
    Member::factory()->for($target)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));
    $root = subtaskCopyIssue($source, $tracker);
    subtaskCopyIssue($source, $tracker, ['parent_id' => $root->id]);

    $form = Livewire::actingAs($user)->test('issues.index', ['project' => $source]);
    expect($form->get('bulkCopySubtasks'))->toBeTrue();

    $form->set('selected', [$root->id])->set('bulkCopyToProjectId', $target->id)->set('bulkCopyToTrackerId', $tracker->id)->set('bulkCopySubtasks', false)->call('applyBulkCopy');
    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(1);

    $form->set('selected', [$root->id])->set('bulkCopyToProjectId', $target->id)->set('bulkCopyToTrackerId', $tracker->id)->call('applyBulkCopy');
    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(3);
});
