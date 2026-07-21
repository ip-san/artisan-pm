<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function privateNotesProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function privateNotesIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a user with set_notes_private can post a private note', function () {
    $project = Project::factory()->create();
    $user = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $issue = privateNotesIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('comment', 'internal note')
        ->set('commentIsPrivate', true)
        ->call('addComment');

    $journal = Journal::where('issue_id', $issue->id)->firstOrFail();
    expect($journal->private_notes)->toBeTrue();
});

test('a user without set_notes_private cannot force a note private', function () {
    $project = Project::factory()->create();
    $user = privateNotesProjectMember($project, ['view_issues', 'edit_issues']);
    $issue = privateNotesIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('comment', 'note')
        ->set('commentIsPrivate', true)
        ->call('addComment');

    $journal = Journal::where('issue_id', $issue->id)->firstOrFail();
    expect($journal->private_notes)->toBeFalse();
});

test('a private note is hidden from a user without view_private_notes', function () {
    $project = Project::factory()->create();
    $author = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $viewer = privateNotesProjectMember($project, ['view_issues']);
    $issue = privateNotesIssue($project);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'secret', 'private_notes' => true]);

    Livewire::actingAs($viewer)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertDontSee('secret');
});

test('a private note is visible to its own author even without view_private_notes', function () {
    $project = Project::factory()->create();
    $author = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $issue = privateNotesIssue($project);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'secret', 'private_notes' => true]);

    Livewire::actingAs($author)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee('secret');
});

test('a private note is visible to a user with view_private_notes', function () {
    $project = Project::factory()->create();
    $author = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $manager = privateNotesProjectMember($project, ['view_issues', 'view_private_notes']);
    $issue = privateNotesIssue($project);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'secret', 'private_notes' => true]);

    Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee('secret');
});
