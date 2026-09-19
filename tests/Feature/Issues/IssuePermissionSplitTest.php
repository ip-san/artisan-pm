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
function splitMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function splitIssue(Project $project, ?User $author = null): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $author?->id ?? User::factory()->create()->id,
    ]);
}

test('edit_own_issues lets an author edit their own issues but nobody else\'s', function () {
    $project = Project::factory()->create();
    $author = splitMember($project, ['view_project', 'view_issues', 'edit_own_issues']);
    $other = splitMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $mine = splitIssue($project, $author);
    $theirs = splitIssue($project, $other);

    expect($author->can('update', $mine))->toBeTrue()
        ->and($author->can('update', $theirs))->toBeFalse()
        ->and($other->can('update', $mine))->toBeTrue();

    Livewire::actingAs($author)->test('issues.form', ['project' => $project, 'issue' => $mine])->assertOk();
    Livewire::actingAs($author)->test('issues.form', ['project' => $project, 'issue' => $theirs])->assertForbidden();
});

test('the API honours edit_own_issues too', function () {
    $project = Project::factory()->create();
    $author = splitMember($project, ['view_issues', 'edit_own_issues']);
    $mine = splitIssue($project, $author);
    $theirs = splitIssue($project);

    Passport::actingAs($author);
    $this->putJson("/api/v1/issues/{$mine->id}", ['subject' => 'Renamed'])->assertOk();
    $this->putJson("/api/v1/issues/{$theirs->id}", ['subject' => 'Renamed'])->assertForbidden();
});

test('add_issue_notes allows commenting without being able to edit fields', function () {
    $project = Project::factory()->create();
    $commenter = splitMember($project, ['view_project', 'view_issues', 'add_issue_notes']);
    $editorOnly = splitMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $issue = splitIssue($project);

    expect($commenter->can('update', $issue))->toBeFalse()
        ->and($commenter->can('addNotes', $issue))->toBeTrue()
        ->and($editorOnly->can('addNotes', $issue))->toBeFalse();

    Livewire::actingAs($commenter)->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('comment', 'A useful remark')
        ->call('addComment')
        ->assertHasNoErrors();
    expect(Journal::query()->where('issue_id', $issue->id)->where('notes', 'A useful remark')->exists())->toBeTrue();

    Livewire::actingAs($editorOnly)->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('comment', 'nope')
        ->call('addComment')
        ->assertForbidden();
});

test('the comment box only shows to someone who may add notes', function () {
    $project = Project::factory()->create();
    $commenter = splitMember($project, ['view_project', 'view_issues', 'add_issue_notes']);
    $viewer = splitMember($project, ['view_project', 'view_issues']);
    $issue = splitIssue($project);

    Livewire::actingAs($commenter)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('コメントを追加');
    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertDontSee('コメントを追加');
});

test('set_own_issues_private applies to issues the user authored only', function () {
    $project = Project::factory()->create();
    $owner = splitMember($project, ['view_project', 'view_issues', 'edit_own_issues', 'set_own_issues_private']);
    $admin = splitMember($project, ['view_project', 'view_issues', 'edit_issues', 'set_issues_private']);
    $mine = splitIssue($project, $owner);
    $theirs = splitIssue($project, $admin);

    expect($owner->can('setPrivateOn', $mine))->toBeTrue()
        ->and($owner->can('setPrivateOn', $theirs))->toBeFalse()
        ->and($owner->can('setPrivate', [Issue::class, $project]))->toBeTrue()
        ->and($admin->can('setPrivateOn', $mine))->toBeTrue();

    Livewire::actingAs($owner)->test('issues.form', ['project' => $project, 'issue' => $mine])
        ->assertSee('非公開課題にする')
        ->set('is_private', true)
        ->call('save')
        ->assertHasNoErrors();
    expect($mine->fresh()->is_private)->toBeTrue();
});

test('without any private permission the flag is neither shown nor saved', function () {
    $project = Project::factory()->create();
    $user = splitMember($project, ['view_project', 'view_issues', 'edit_own_issues']);
    $mine = splitIssue($project, $user);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $mine])
        ->assertDontSee('非公開課題にする')
        ->set('is_private', true)
        ->call('save');

    expect($mine->fresh()->is_private)->toBeFalse();
});

test('manage_subtasks controls whether the parent can be set', function () {
    $project = Project::factory()->create();
    $manager = splitMember($project, ['view_project', 'view_issues', 'edit_issues', 'manage_subtasks']);
    $editor = splitMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $parent = splitIssue($project);
    $child = splitIssue($project);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $child])
        ->assertDontSee('親課題ID')
        ->set('parent_id', $parent->id)
        ->call('save');
    expect($child->fresh()->parent_id)->toBeNull();

    Livewire::actingAs($manager)->test('issues.form', ['project' => $project, 'issue' => $child->fresh()])
        ->assertSee('親課題ID')
        ->set('parent_id', $parent->id)
        ->call('save')
        ->assertHasNoErrors();
    expect($child->fresh()->parent_id)->toBe($parent->id);
});

test('an editor without manage_subtasks keeps the existing parent when saving', function () {
    $project = Project::factory()->create();
    $editor = splitMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $parent = splitIssue($project);
    $child = splitIssue($project);
    $child->update(['parent_id' => $parent->id]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $child])
        ->set('subject', 'Retitled')
        ->call('save')
        ->assertHasNoErrors();

    expect($child->fresh()->only(['parent_id', 'subject']))->toBe(['parent_id' => $parent->id, 'subject' => 'Retitled']);
});
