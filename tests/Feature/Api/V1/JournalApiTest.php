<?php

use App\Enums\IssueVisibility;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

function apiJournalMember(Project $project, array $permissions, string $issuesVisibility = 'all'): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $issuesVisibility]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => User::factory()->create()->id, 'notes' => 'x']);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'y'])->assertUnauthorized();
});

test('a user with edit_own_issue_notes can update their own journal via the api', function () {
    $project = Project::factory()->create();
    $author = apiJournalMember($project, ['view_issues', 'edit_own_issue_notes']);
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Original']);

    Passport::actingAs($author);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'Edited'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Edited');

    expect($journal->fresh()->notes)->toBe('Edited');
});

test('a user without edit_own_issue_notes cannot update someone else\'s journal via the api', function () {
    $project = Project::factory()->create();
    $author = apiJournalMember($project, ['view_issues']);
    $attacker = apiJournalMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Original']);

    Passport::actingAs($attacker);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'Hacked'])
        ->assertForbidden();

    expect($journal->fresh()->notes)->toBe('Original');
});

test('a user with edit_issue_notes can update any journal via the api, including clearing it to blank', function () {
    $project = Project::factory()->create();
    $author = apiJournalMember($project, ['view_issues']);
    $manager = apiJournalMember($project, ['view_issues', 'edit_issue_notes']);
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Remove me']);
    $journal->details()->create(['property' => 'attr', 'prop_key' => 'subject', 'old_value' => 'a', 'new_value' => 'b']);

    Passport::actingAs($manager);

    // The ConvertEmptyStringsToNull middleware normalizes '' to null
    // before validation — functionally equivalent for blank()/isEmpty()
    // checks, so this asserts null rather than ''.
    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => ''])
        ->assertOk()
        ->assertJsonPath('data.notes', null);

    $journal->refresh();
    expect($journal->notes)->toBeNull();
    expect($journal->details()->exists())->toBeTrue();
});

test('an attribute-only journal with no notes cannot be updated via the api', function () {
    $project = Project::factory()->create();
    $author = apiJournalMember($project, ['view_issues', 'edit_own_issue_notes', 'edit_issue_notes']);
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => null]);

    Passport::actingAs($author);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'Should not attach'])->assertForbidden();

    expect(Journal::find($journal->id)->notes)->toBeNull();
});

test('a private note is not editable via the api by a user without view_private_notes', function () {
    $project = Project::factory()->create();
    $author = apiJournalMember($project, ['view_issues']);
    $attacker = apiJournalMember($project, ['view_issues', 'edit_issue_notes']);
    $issue = Issue::factory()->for($project)->create();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Private', 'private_notes' => true]);

    Passport::actingAs($attacker);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'Hacked'])->assertForbidden();

    expect($journal->fresh()->notes)->toBe('Private');
});

test('a journal on an issue hidden by visibility scope cannot be updated via the api', function () {
    $project = Project::factory()->create();
    $attacker = apiJournalMember($project, ['view_issues', 'edit_issue_notes', 'edit_own_issue_notes'], issuesVisibility: IssueVisibility::Default->value);
    $other = User::factory()->create();
    $issue = Issue::factory()->for($project)->create(['is_private' => true, 'author_id' => $other->id, 'assigned_to_id' => $other->id]);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $other->id, 'notes' => 'Hidden issue note']);

    Passport::actingAs($attacker);

    $this->putJson("/api/v1/journals/{$journal->id}", ['notes' => 'Hacked'])->assertForbidden();

    expect($journal->fresh()->notes)->toBe('Hidden issue note');
});
