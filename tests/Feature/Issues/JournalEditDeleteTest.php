<?php

use App\Enums\IssueVisibility;
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

function journalEditIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

function journalEditMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a user with edit_own_issue_notes can edit their own comment', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues', 'edit_own_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Original text']);

    Livewire::actingAs($author)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)
        ->set('editingJournalNotes', 'Edited text')
        ->call('saveJournalEdit')
        ->assertHasNoErrors();

    expect($journal->fresh()->notes)->toBe('Edited text');
});

test('edit_own_issue_notes does not let a user edit someone else\'s comment', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues']);
    $other = journalEditMember($project, ['view_issues', 'edit_own_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Original text']);

    Livewire::actingAs($other)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)
        ->assertForbidden();

    expect($journal->fresh()->notes)->toBe('Original text');
});

test('edit_issue_notes lets a user edit any comment', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues']);
    $manager = journalEditMember($project, ['view_issues', 'edit_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Original text']);

    Livewire::actingAs($manager)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)
        ->set('editingJournalNotes', 'Edited by manager')
        ->call('saveJournalEdit')
        ->assertHasNoErrors();

    expect($journal->fresh()->notes)->toBe('Edited by manager');
});

test('clearing a comment to blank removes it but keeps the attribute changes in the same journal visible', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues', 'edit_own_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Remove me']);
    $journal->details()->create(['property' => 'attr', 'prop_key' => 'subject', 'old_value' => 'a', 'new_value' => 'b']);

    Livewire::actingAs($author)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)
        ->set('editingJournalNotes', '')
        ->call('saveJournalEdit')
        ->assertHasNoErrors();

    $journal->refresh();
    expect($journal->notes)->toBe('');
    expect($journal->details()->exists())->toBeTrue();
    expect($journal->isEmpty())->toBeFalse();
});

test('clearing a comment with no attribute changes makes the journal disappear from the issue history', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues', 'edit_own_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Remove me']);

    Livewire::actingAs($author)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)
        ->set('editingJournalNotes', '')
        ->call('saveJournalEdit')
        ->assertHasNoErrors();

    expect($journal->fresh()->isEmpty())->toBeTrue();
});

test('a user with edit_issue_notes but not view_private_notes cannot edit a private note by posting the journal id directly', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues']);
    $attacker = journalEditMember($project, ['view_issues', 'edit_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Private note', 'private_notes' => true]);

    // Skips startEditingJournal (which would never surface a private
    // journal's id to this user) and posts the id straight to the save
    // action, the same way a tampered request would.
    Livewire::actingAs($attacker)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('editingJournalId', $journal->id)
        ->set('editingJournalNotes', 'Hacked text')
        ->call('saveJournalEdit');

    expect($journal->fresh()->notes)->toBe('Private note');
});

test('a user cannot edit a journal on an issue their role cannot view by posting the journal id directly', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issue_notes', 'edit_own_issue_notes'], 'issues_visibility' => IssueVisibility::Default->value]);
    $attacker = User::factory()->create();
    Member::factory()->for($project)->for($attacker)->create()->roles()->attach($role);

    $other = User::factory()->create();
    $issue = journalEditIssue($project);
    $issue->update(['is_private' => true, 'author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $other->id, 'notes' => 'Hidden issue note']);

    Livewire::actingAs($attacker)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertForbidden();

    expect($attacker->can('update', $journal))->toBeFalse();
    expect(Journal::find($journal->id)->notes)->toBe('Hidden issue note');
});

test('an attribute-only journal with no notes cannot be edited', function () {
    $project = Project::factory()->create();
    $issue = journalEditIssue($project);
    $author = journalEditMember($project, ['view_issues', 'edit_own_issue_notes', 'edit_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => null]);

    expect($author->can('update', $journal))->toBeFalse();
});
