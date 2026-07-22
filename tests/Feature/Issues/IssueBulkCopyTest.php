<?php

use App\Enums\IssueRelationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Livewire\Livewire;

function bulkCopyMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function bulkCopyIssue(Project $project, Tracker $tracker): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Original subject',
    ]);
}

test('a user with copy_issues can bulk copy selected issues to another project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = bulkCopyMember($source, ['view_issues', 'copy_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $issueA = bulkCopyIssue($source, $sourceTracker);
    $issueB = bulkCopyIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issueA->id, $issueB->id])
        ->set('bulkCopyToProjectId', $target->id)
        ->set('bulkCopyToTrackerId', $targetTracker->id)
        ->call('applyBulkCopy');

    expect($issueA->fresh()->project_id)->toBe($source->id)
        ->and(Issue::query()->where('project_id', $target->id)->count())->toBe(2)
        ->and(Issue::query()->where('project_id', $target->id)->pluck('subject')->all())
        ->toBe(['Original subject', 'Original subject']);
});

test('copying an issue creates a copied_to relation back to the source', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author);

    $relation = IssueRelation::query()
        ->where('issue_from_id', $source->id)
        ->where('issue_to_id', $copy->id)
        ->first();

    expect($relation)->not->toBeNull()
        ->and($relation->relation_type)->toBe(IssueRelationType::CopiedTo);
});

test('a user without copy_issues cannot bulk copy issues', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );
    $issue = bulkCopyIssue($source, $tracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkCopyToProjectId', $target->id)
        ->set('bulkCopyToTrackerId', $tracker->id)
        ->call('applyBulkCopy')
        ->assertForbidden();

    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(0);
});

test('bulk copy carries over custom field values relevant to the target tracker', function () {
    $source = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues', 'copy_issues', 'add_issues']);

    $issue = bulkCopyIssue($source, $tracker);
    $issue->setCustomFieldValues([$field->id => 'High']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkCopyToProjectId', $source->id)
        ->set('bulkCopyToTrackerId', $tracker->id)
        ->call('applyBulkCopy');

    $copy = Issue::query()->where('id', '!=', $issue->id)->where('project_id', $source->id)->sole();

    expect($copy->customValue($field))->toBe('High');
});

test('bulk copy is not offered without copy_issues', function () {
    $source = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues', 'add_issues']);
    $issue = bulkCopyIssue($source, $tracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->assertDontSee('コピーして複製');
});
