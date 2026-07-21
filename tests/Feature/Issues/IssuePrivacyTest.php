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

function privacyIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

function privacyMember(Project $project, array $permissions, IssueVisibility $visibility = IssueVisibility::All): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility->value]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('a user with set_issues_private can mark an issue private on creation', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = privacyMember($project, ['view_issues', 'add_issues', 'set_issues_private']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Sensitive issue')
        ->set('is_private', true)
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Sensitive issue')->firstOrFail();
    expect($issue->is_private)->toBeTrue();
});

test('a user without set_issues_private cannot mark an issue private', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = privacyMember($project, ['view_issues', 'add_issues']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Attempted private issue')
        ->set('is_private', true)
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Attempted private issue')->firstOrFail();
    expect($issue->is_private)->toBeFalse();
});

test('a lower-permission editor cannot silently un-privatize an existing private issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = privacyIssue($project, ['tracker_id' => $tracker->id, 'is_private' => true]);
    $editor = privacyMember($project, ['view_issues', 'edit_issues']);
    Issue::query()->whereKey($issue->id)->update(['author_id' => $editor->id]);

    Livewire::actingAs($editor)
        ->test('issues.form', ['project' => $project, 'issue' => $issue->fresh()])
        ->set('subject', 'Edited subject')
        ->call('save')
        ->assertRedirect();

    expect($issue->fresh()->is_private)->toBeTrue();
});

test('all-visibility role sees a private issue regardless of authorship', function () {
    $project = Project::factory()->create();
    $issue = privacyIssue($project, ['is_private' => true]);
    $user = privacyMember($project, ['view_issues'], IssueVisibility::All);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertOk();
});

test('default-visibility role hides a private issue unless author or assignee', function () {
    $project = Project::factory()->create();
    $other = User::factory()->create();
    $issue = privacyIssue($project, ['is_private' => true, 'author_id' => $other->id]);
    $user = privacyMember($project, ['view_issues'], IssueVisibility::Default);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertForbidden();
});

test('default-visibility role can see their own private issue as author or assignee', function () {
    $project = Project::factory()->create();
    $user = privacyMember($project, ['view_issues'], IssueVisibility::Default);
    $asAuthor = privacyIssue($project, ['is_private' => true, 'author_id' => $user->id]);
    $asAssignee = privacyIssue($project, ['is_private' => true, 'assigned_to_id' => $user->id]);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $asAuthor])->assertOk();
    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $asAssignee])->assertOk();
});

test('default-visibility role can see a non-private issue by anyone', function () {
    $project = Project::factory()->create();
    $other = User::factory()->create();
    $issue = privacyIssue($project, ['is_private' => false, 'author_id' => $other->id]);
    $user = privacyMember($project, ['view_issues'], IssueVisibility::Default);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertOk();
});

test('own-visibility role only sees their own issues regardless of the private flag', function () {
    $project = Project::factory()->create();
    $user = privacyMember($project, ['view_issues'], IssueVisibility::Own);
    $notMine = privacyIssue($project, ['is_private' => false]);

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $notMine])->assertForbidden();
});

test('the issue list hides private issues from a default-visibility non-author', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $other = User::factory()->create();
    $privateIssue = privacyIssue($project, ['tracker_id' => $tracker->id, 'is_private' => true, 'author_id' => $other->id]);
    $publicIssue = privacyIssue($project, ['tracker_id' => $tracker->id, 'is_private' => false]);
    $user = privacyMember($project, ['view_issues'], IssueVisibility::Default);

    $ids = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->get('issues')
        ->pluck('id');

    expect($ids)->toContain($publicIssue->id)->not->toContain($privateIssue->id);
});
