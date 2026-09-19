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
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function journalMetaMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

/**
 * @return array{project: Project, issue: Issue}
 */
function journalMetaIssue(): array
{
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    return compact('project', 'issue');
}

test('editing a comment records who edited it and shows it', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $author = journalMetaMember($project, ['view_issues', 'edit_own_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'before']);

    Livewire::actingAs($author)->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertDontSee('data-journal-edited', false)
        ->call('startEditingJournal', $journal->id)
        ->set('editingJournalNotes', 'after')
        ->call('saveJournalEdit')
        ->assertSee($author->name.' が編集');

    expect($journal->fresh()->updated_by_id)->toBe($author->id)->and($journal->fresh()->notes)->toBe('after');
});

test('an editor other than the author is the one recorded', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $author = journalMetaMember($project, ['view_issues']);
    $editor = journalMetaMember($project, ['view_issues', 'edit_issue_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'before']);

    Livewire::actingAs($editor)->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('startEditingJournal', $journal->id)->set('editingJournalNotes', 'fixed')->call('saveJournalEdit');

    expect($journal->fresh()->updated_by_id)->toBe($editor->id);
});

test('the edit form can flip the private flag for someone with set_notes_private', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $author = journalMetaMember($project, ['view_issues', 'edit_own_issue_notes', 'set_notes_private', 'view_private_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'note']);

    $page = Livewire::actingAs($author)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('startEditingJournal', $journal->id);
    $page->assertSet('editingJournalPrivate', false)->assertSee('非公開コメントにする');
    $page->set('editingJournalPrivate', true)->call('saveJournalEdit');
    expect($journal->fresh()->private_notes)->toBeTrue();

    Livewire::actingAs($author)->test('issues.show', ['project' => $project, 'issue' => $issue->fresh()])
        ->call('startEditingJournal', $journal->id)->assertSet('editingJournalPrivate', true)
        ->set('editingJournalPrivate', false)->call('saveJournalEdit');
    expect($journal->fresh()->private_notes)->toBeFalse();
});

test('without set_notes_private the private flag is neither offered nor changed', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $author = journalMetaMember($project, ['view_issues', 'edit_own_issue_notes', 'view_private_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'note', 'private_notes' => true]);

    $page = Livewire::actingAs($author)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('startEditingJournal', $journal->id);
    $page->assertDontSee('非公開コメントにする')->set('editingJournalPrivate', false)->set('editingJournalNotes', 'edited')->call('saveJournalEdit');

    expect($journal->fresh()->private_notes)->toBeTrue()->and($journal->fresh()->notes)->toBe('edited');
});

test('the api edit records the editor and honours private_notes only with set_notes_private', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $allowed = journalMetaMember($project, ['view_issues', 'edit_own_issue_notes', 'set_notes_private', 'view_private_notes']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $allowed->id, 'notes' => 'note']);

    Passport::actingAs($allowed);
    $this->putJson("/api/v1/journals/{$journal->id}", ['private_notes' => true])->assertOk()->assertJsonPath('data.private_notes', true)->assertJsonPath('data.updated_by_id', $allowed->id);

    $denied = journalMetaMember($project, ['view_issues', 'edit_own_issue_notes', 'view_private_notes']);
    $ownJournal = Journal::create(['issue_id' => $issue->id, 'user_id' => $denied->id, 'notes' => 'mine']);
    Passport::actingAs($denied);
    $this->putJson("/api/v1/journals/{$ownJournal->id}", ['notes' => 'changed', 'private_notes' => true])->assertOk();

    expect($ownJournal->fresh()->private_notes)->toBeFalse()->and($ownJournal->fresh()->notes)->toBe('changed');
});

test('a never-edited journal has no editor and shows no edited marker', function () {
    ['project' => $project, 'issue' => $issue] = journalMetaIssue();
    $author = journalMetaMember($project, ['view_issues']);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'plain']);

    Livewire::actingAs($author)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertDontSee('data-journal-edited', false);

    expect($journal->fresh()->updated_by_id)->toBeNull();
});
