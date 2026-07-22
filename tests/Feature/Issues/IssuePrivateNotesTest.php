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
    $tracker = Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
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

test('editing an issue with a private comment and attribute changes together splits into a public details journal and a private notes journal', function () {
    $project = Project::factory()->create();
    $user = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $priority = Enumeration::factory()->create();
    $issue = privateNotesIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('priority_id', $priority->id)
        ->set('comment', 'internal reasoning for this change')
        ->set('commentIsPrivate', true)
        ->call('save');

    $journals = $issue->fresh()->journals()->with('details')->get();

    expect($journals)->toHaveCount(2);

    $detailsJournal = $journals->firstWhere(fn (Journal $journal) => $journal->details->isNotEmpty());
    $notesJournal = $journals->firstWhere(fn (Journal $journal) => filled($journal->notes));

    expect($detailsJournal)->not->toBeNull()
        ->and($detailsJournal->notes)->toBeNull()
        ->and($detailsJournal->private_notes)->toBeFalse()
        ->and($detailsJournal->details->firstWhere('prop_key', 'priority_id'))->not->toBeNull()
        ->and($notesJournal)->not->toBeNull()
        ->and($notesJournal->notes)->toBe('internal reasoning for this change')
        ->and($notesJournal->private_notes)->toBeTrue()
        ->and($notesJournal->details)->toBeEmpty();
});

test('editing an issue with a private comment but no attribute changes does not split the journal', function () {
    $project = Project::factory()->create();
    $user = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $issue = privateNotesIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('comment', 'just a private note, no changes')
        ->set('commentIsPrivate', true)
        ->call('save');

    $journals = $issue->fresh()->journals;

    expect($journals)->toHaveCount(1)
        ->and($journals->first()->notes)->toBe('just a private note, no changes')
        ->and($journals->first()->private_notes)->toBeTrue();
});

test('editing an issue with a public comment and attribute changes together still records a single journal', function () {
    $project = Project::factory()->create();
    $user = privateNotesProjectMember($project, ['view_issues', 'edit_issues', 'set_notes_private']);
    $priority = Enumeration::factory()->create();
    $issue = privateNotesIssue($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('priority_id', $priority->id)
        ->set('comment', 'a normal public comment')
        ->call('save');

    $journals = $issue->fresh()->journals()->with('details')->get();

    expect($journals)->toHaveCount(1)
        ->and($journals->first()->notes)->toBe('a normal public comment')
        ->and($journals->first()->private_notes)->toBeFalse()
        ->and($journals->first()->details->firstWhere('prop_key', 'priority_id'))->not->toBeNull();
});
